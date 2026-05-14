<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\LeaveLedgerEntry;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Services\LeaveLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CancelLeaveRequestUseCase
{
    /**
     * Cancel a pending or approved leave request.
     *
     * Guards:
     *  - Request must be in pending or approved status
     *
     * If approved: creates a release credit entry to inverse the reservation.
     * No ledger write if pending (no reservation was made).
     */
    public function execute(
        LeaveRequest $request,
        ?string $cancellationReason = null,
    ): LeaveRequest {
        // Guard: must be pending or approved
        if (! in_array($request->status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED], true)) {
            throw new \DomainException(
                "Cannot cancel leave request — current status is '{$request->status}'. Only pending or approved requests can be cancelled."
            );
        }

        $wasApproved = $request->status === LeaveRequest::STATUS_APPROVED;

        return DB::transaction(function () use ($request, $cancellationReason, $wasApproved) {
            // If approved, release the reservation
            if ($wasApproved && $request->ledger_correlation_id) {
                $periodKey = $request->date_from->format('Y-m');

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
                    description: "Release reservation for cancelled leave request #{$request->id}",
                    auditCategory: 'workforce.leave.request',
                );
            }

            $request->transitionTo(LeaveRequest::STATUS_CANCELLED);

            if ($cancellationReason) {
                $request->review_note = $cancellationReason;
            }

            $request->save();

            app(AuditLogger::class)->logCompany(
                companyId: $request->company_id,
                action: 'leave_request.cancelled',
                targetType: 'leave_request',
                targetId: (string) $request->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.leave.request',
                        'employee_id' => $request->employee_id,
                        'was_approved' => $wasApproved,
                        'reservation_released' => $wasApproved,
                        'cancellation_reason' => $cancellationReason,
                    ],
                ],
            );

            return $request;
        });
    }
}
