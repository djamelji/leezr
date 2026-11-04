<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Policies\CompanyPolicyResolver;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Facades\DB;

class SubmitTimesheetUseCase
{
    // B3-checkpoint (ADR-699) — atomicité : transaction + verrou + re-check d'état dans le verrou.
    public function execute(TimesheetPeriod $period, int $submittedBy): TimesheetPeriod
    {
        return DB::transaction(function () use ($period, $submittedBy) {
            $locked = TimesheetPeriod::withoutGlobalScopes()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            // I3: Must be draft
            if ($locked->status !== TimesheetPeriod::STATUS_DRAFT) {
                throw new \DomainException("Cannot submit timesheet: status is '{$locked->status}', expected 'draft'.");
            }

            // I10: Employee not terminated
            $employee = Employee::withoutGlobalScopes()->find($locked->employee_id);
            if ($employee && $employee->status === Employee::STATUS_TERMINATED) {
                throw new \DomainException('Cannot submit timesheet for a terminated employee.');
            }

            // Must have at least one line
            if ($locked->lines()->count() === 0) {
                throw new \DomainException('Cannot submit an empty timesheet. Generate lines first.');
            }

            // I5: Capture policy snapshot at submission
            $policyResolver = app(CompanyPolicyResolver::class);
            $policySnapshot = $policyResolver->snapshot($locked->company_id, 'workforce');

            $locked->transitionTo(TimesheetPeriod::STATUS_SUBMITTED);
            $locked->submitted_by = $submittedBy;
            $locked->submitted_at = now();
            $locked->policy_snapshot = $policySnapshot;
            $locked->save();

            app(AuditLogger::class)->logCompany(
                companyId: $locked->company_id,
                action: 'timesheet.submitted',
                targetType: 'timesheet_period',
                targetId: (string) $locked->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.timesheet',
                        'employee_id' => $locked->employee_id,
                        'submitted_by' => $submittedBy,
                        'anomaly_count' => $locked->anomaly_count,
                    ],
                ],
            );

            return $locked;
        });
    }
}
