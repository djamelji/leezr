<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimesheetPeriod;

class ApproveTimesheetUseCase
{
    /**
     * Approve a submitted timesheet.
     * CORR #8: If error anomalies exist, approval_note is required.
     */
    public function execute(
        TimesheetPeriod $period,
        int $approvedBy,
        ?string $approvalNote = null,
    ): TimesheetPeriod {
        // Must be submitted
        if ($period->status !== TimesheetPeriod::STATUS_SUBMITTED) {
            throw new \DomainException("Cannot approve timesheet: status is '{$period->status}', expected 'submitted'.");
        }

        // CORR #8: Error anomalies require acknowledgment
        $hasErrors = $period->lines()
            ->get()
            ->contains(fn ($line) => $line->hasErrorAnomalies());

        if ($hasErrors && empty($approvalNote)) {
            throw new \DomainException(
                'This timesheet has error-level anomalies. An approval note is required to acknowledge them.'
            );
        }

        $period->transitionTo(TimesheetPeriod::STATUS_APPROVED);
        $period->approved_by = $approvedBy;
        $period->approved_at = now();
        $period->approval_note = $approvalNote;
        $period->save();

        app(AuditLogger::class)->logCompany(
            companyId: $period->company_id,
            action: 'timesheet.approved',
            targetType: 'timesheet_period',
            targetId: (string) $period->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.timesheet',
                    'employee_id' => $period->employee_id,
                    'approved_by' => $approvedBy,
                    'had_error_anomalies' => $hasErrors,
                    'approval_note' => $approvalNote,
                ],
            ],
        );

        return $period;
    }
}
