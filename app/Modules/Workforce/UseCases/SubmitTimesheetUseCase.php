<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Policies\CompanyPolicyResolver;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimesheetPeriod;

class SubmitTimesheetUseCase
{
    public function execute(TimesheetPeriod $period, int $submittedBy): TimesheetPeriod
    {
        // I3: Must be draft
        if ($period->status !== TimesheetPeriod::STATUS_DRAFT) {
            throw new \DomainException("Cannot submit timesheet: status is '{$period->status}', expected 'draft'.");
        }

        // I10: Employee not terminated
        $employee = Employee::withoutGlobalScopes()->find($period->employee_id);
        if ($employee && $employee->status === Employee::STATUS_TERMINATED) {
            throw new \DomainException('Cannot submit timesheet for a terminated employee.');
        }

        // Must have at least one line
        if ($period->lines()->count() === 0) {
            throw new \DomainException('Cannot submit an empty timesheet. Generate lines first.');
        }

        // I5: Capture policy snapshot at submission
        $policyResolver = app(CompanyPolicyResolver::class);
        $policySnapshot = $policyResolver->snapshot($period->company_id, 'workforce');

        $period->transitionTo(TimesheetPeriod::STATUS_SUBMITTED);
        $period->submitted_by = $submittedBy;
        $period->submitted_at = now();
        $period->policy_snapshot = $policySnapshot;
        $period->save();

        app(AuditLogger::class)->logCompany(
            companyId: $period->company_id,
            action: 'timesheet.submitted',
            targetType: 'timesheet_period',
            targetId: (string) $period->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.timesheet',
                    'employee_id' => $period->employee_id,
                    'submitted_by' => $submittedBy,
                    'anomaly_count' => $period->anomaly_count,
                ],
            ],
        );

        return $period;
    }
}
