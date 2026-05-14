<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimesheetPeriod;

class RejectTimesheetUseCase
{
    public function execute(
        TimesheetPeriod $period,
        int $rejectedBy,
        string $rejectionReason,
    ): TimesheetPeriod {
        // Must be submitted
        if ($period->status !== TimesheetPeriod::STATUS_SUBMITTED) {
            throw new \DomainException("Cannot reject timesheet: status is '{$period->status}', expected 'submitted'.");
        }

        if (empty(trim($rejectionReason))) {
            throw new \DomainException('A rejection reason is required.');
        }

        $period->transitionTo(TimesheetPeriod::STATUS_REJECTED);
        $period->rejected_by = $rejectedBy;
        $period->rejected_at = now();
        $period->rejection_reason = $rejectionReason;
        $period->save();

        app(AuditLogger::class)->logCompany(
            companyId: $period->company_id,
            action: 'timesheet.rejected',
            targetType: 'timesheet_period',
            targetId: (string) $period->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.timesheet',
                    'employee_id' => $period->employee_id,
                    'rejected_by' => $rejectedBy,
                    'rejection_reason' => $rejectionReason,
                ],
            ],
        );

        return $period;
    }
}
