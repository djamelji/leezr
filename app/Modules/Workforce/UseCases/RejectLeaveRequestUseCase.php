<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\LeaveRequest;
use Illuminate\Support\Facades\DB;

class RejectLeaveRequestUseCase
{
    /**
     * Reject a pending leave request.
     *
     * Guards:
     *  - Request must be in pending status
     *
     * No ledger write — rejection does not affect balances.
     */
    public function execute(
        LeaveRequest $request,
        ?int $reviewerId = null,
        ?string $reviewNote = null,
    ): LeaveRequest {
        // Guard: must be pending
        if ($request->status !== LeaveRequest::STATUS_PENDING) {
            throw new \DomainException(
                "Cannot reject leave request — current status is '{$request->status}', expected 'pending'."
            );
        }

        return DB::transaction(function () use ($request, $reviewerId, $reviewNote) {
            $request->transitionTo(LeaveRequest::STATUS_REJECTED);
            $request->reviewer_id = $reviewerId;
            $request->review_note = $reviewNote;
            $request->reviewed_at = now();
            $request->save();

            app(AuditLogger::class)->logCompany(
                companyId: $request->company_id,
                action: 'leave_request.rejected',
                targetType: 'leave_request',
                targetId: (string) $request->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.leave.request',
                        'employee_id' => $request->employee_id,
                        'reviewer_id' => $reviewerId,
                        'review_note' => $reviewNote,
                    ],
                ],
            );

            return $request;
        });
    }
}
