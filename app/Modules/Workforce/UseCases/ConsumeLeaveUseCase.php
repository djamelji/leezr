<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsumeLeaveUseCase
{
    /**
     * Consume an approved leave request — definitive deduction from balance.
     *
     * Guards:
     *  - Request must be in approved status
     *  - Must not already be consumed (idempotent via idempotency_key)
     *
     * Creates 2 ledger entries atomically:
     *  1. Release credit — cancels the reservation
     *  2. Consumption debit — definitive deduction
     *
     * Does NOT modify Employee.status.
     */
    public function execute(LeaveRequest $request): LeaveRequest
    {
        // Guard: must be approved
        if ($request->status !== LeaveRequest::STATUS_APPROVED) {
            throw new \DomainException(
                "Cannot consume leave request — current status is '{$request->status}', expected 'approved'."
            );
        }

        $idempotencyKey = "consume:{$request->id}";

        // Idempotency check: if already consumed, return as-is
        $existing = LeaveLedgerEntry::withoutCompanyScope()
            ->where('idempotency_key', $idempotencyKey)
            ->exists();

        if ($existing) {
            // Already consumed — reload status and return
            $request->refresh();

            return $request;
        }

        $periodKey = $request->date_from->format('Y-m');
        $consumptionCorrelationId = (string) Str::uuid();

        return DB::transaction(function () use ($request, $periodKey, $idempotencyKey, $consumptionCorrelationId) {
            // 1. Release credit — cancels the reservation
            LeaveLedger::credit(
                companyId: $request->company_id,
                employeeId: $request->employee_id,
                leaveTypeId: $request->leave_type_id,
                amount: $request->days_count_hundredths,
                entryType: LeaveLedgerEntry::TYPE_RELEASE,
                periodKey: $periodKey,
                correlationId: $request->ledger_correlation_id,
                referenceType: 'leave_request',
                referenceId: $request->id,
                description: "Release reservation for consumption of leave request #{$request->id}",
                auditCategory: 'workforce.leave.ledger',
            );

            // 2. Consumption debit — definitive
            LeaveLedger::debit(
                companyId: $request->company_id,
                employeeId: $request->employee_id,
                leaveTypeId: $request->leave_type_id,
                amount: $request->days_count_hundredths,
                entryType: LeaveLedgerEntry::TYPE_CONSUMPTION,
                periodKey: $periodKey,
                correlationId: $consumptionCorrelationId,
                referenceType: 'leave_request',
                referenceId: $request->id,
                idempotencyKey: $idempotencyKey,
                description: "Consumption of leave request #{$request->id}",
                auditCategory: 'workforce.leave.ledger',
            );

            // Transition to consumed
            $request->transitionTo(LeaveRequest::STATUS_CONSUMED);
            $request->consumed_at = now();
            $request->save();

            app(AuditLogger::class)->logCompany(
                companyId: $request->company_id,
                action: 'leave_request.consumed',
                targetType: 'leave_request',
                targetId: (string) $request->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.leave.ledger',
                        'employee_id' => $request->employee_id,
                        'leave_type_id' => $request->leave_type_id,
                        'days_count_hundredths' => $request->days_count_hundredths,
                        'reservation_correlation_id' => $request->ledger_correlation_id,
                        'consumption_correlation_id' => $consumptionCorrelationId,
                    ],
                ],
            );

            return $request;
        });
    }
}
