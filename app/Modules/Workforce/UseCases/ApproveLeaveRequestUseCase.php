<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApproveLeaveRequestUseCase
{
    /**
     * Approve a pending leave request.
     *
     * Guards:
     *  - Request must be in pending status
     *  - Available balance must be >= days_count_hundredths
     *
     * Creates a reservation debit entry in the ledger and sets
     * the ledger_correlation_id on the request.
     */
    public function execute(
        LeaveRequest $request,
        ?int $reviewerId = null,
        ?string $reviewNote = null,
    ): LeaveRequest {
        // Guard: must be pending
        if ($request->status !== LeaveRequest::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot approve leave request — current status is '{$request->status}', expected 'pending'."
            );
        }

        // Guard: sufficient balance
        $balance = LeaveLedger::computeBalance(
            $request->company_id,
            $request->employee_id,
            $request->leave_type_id,
        );

        if ($balance['available'] < $request->days_count_hundredths) {
            throw new \DomainException(
                "Insufficient leave balance for approval. Available: {$balance['available']}, requested: {$request->days_count_hundredths}"
            );
        }

        $periodKey = $request->date_from->format('Y-m');
        $correlationId = (string) Str::uuid();

        return DB::transaction(function () use ($request, $reviewerId, $reviewNote, $periodKey, $correlationId) {
            // Transition state
            $request->transitionTo(LeaveRequest::STATUS_APPROVED);
            $request->reviewer_id = $reviewerId;
            $request->review_note = $reviewNote;
            $request->reviewed_at = now();
            $request->ledger_correlation_id = $correlationId;
            $request->save();

            // Create reservation debit in ledger
            LeaveLedger::debit(
                companyId: $request->company_id,
                employeeId: $request->employee_id,
                leaveTypeId: $request->leave_type_id,
                amount: $request->days_count_hundredths,
                entryType: LeaveLedgerEntry::TYPE_RESERVATION,
                periodKey: $periodKey,
                correlationId: $correlationId,
                referenceType: 'leave_request',
                referenceId: $request->id,
                description: "Reservation for leave request #{$request->id}",
                actorType: $reviewerId ? 'user' : 'system',
                actorId: $reviewerId,
                auditCategory: 'workforce.leave.request',
            );

            app(AuditLogger::class)->logCompany(
                companyId: $request->company_id,
                action: 'leave_request.approved',
                targetType: 'leave_request',
                targetId: (string) $request->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.leave.request',
                        'employee_id' => $request->employee_id,
                        'reviewer_id' => $reviewerId,
                        'days_count_hundredths' => $request->days_count_hundredths,
                        'correlation_id' => $correlationId,
                    ],
                ],
            );

            return $request;
        });
    }
}
