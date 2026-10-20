<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\TimesheetPeriod;
use Carbon\Carbon;

class LockTimesheetUseCase
{
    /**
     * Lock an approved timesheet for payroll consumption.
     * CORR #3: Requires workforce_payroll module activated + payroll_prepare permission.
     * Permission check is responsibility of the controller/middleware.
     * Module check is done here as business rule.
     */
    public function execute(TimesheetPeriod $period, int $lockedBy): TimesheetPeriod
    {
        if ($period->status !== TimesheetPeriod::STATUS_APPROVED) {
            throw new \DomainException("Cannot lock timesheet: status is '{$period->status}', expected 'approved'.");
        }

        // CORR #3: Module workforce_payroll must be enabled
        $payrollActive = CompanyModuleActivationReason::where('company_id', $period->company_id)
            ->where('module_key', 'workforce_payroll')
            ->exists();

        if (! $payrollActive) {
            throw new \DomainException(
                'Cannot lock timesheet: the Payroll module (workforce_payroll) is not activated for this company.'
            );
        }

        // LOT H: freeze the EFFECTIVE normative rules in force at the end of the period into the
        // period snapshot. Payroll then consumes the locked period's computed totals; a later rule
        // change does NOT recompute a locked period (timesheet lines are immutable once not draft).
        $coreCompany = \App\Core\Models\Company::withoutGlobalScopes()->find($period->company_id);
        if ($coreCompany) {
            $contract = Employee::withoutGlobalScopes()->find($period->employee_id)?->currentContract;
            $resolver = app(WorkforceRuleResolver::class);
            $at = Carbon::parse($period->period_end);
            $snapshot = $period->policy_snapshot ?? [];
            $snapshot['workforce_rules'] = [
                'captured_at' => now()->toIso8601String(),
                'effective_at' => $at->toDateString(),
                'work_time' => $resolver->resolveWorkTimeRules($coreCompany, $at, $contract),
                'breaks' => $resolver->resolveBreakRules($coreCompany, $at, $contract),
            ];
            $period->policy_snapshot = $snapshot;
        }

        $period->transitionTo(TimesheetPeriod::STATUS_LOCKED);
        $period->locked_at = now();
        $period->locked_by = $lockedBy;
        $period->save();

        app(AuditLogger::class)->logCompany(
            companyId: $period->company_id,
            action: 'timesheet.locked',
            targetType: 'timesheet_period',
            targetId: (string) $period->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.timesheet',
                    'employee_id' => $period->employee_id,
                    'locked_by' => $lockedBy,
                ],
            ],
        );

        return $period;
    }
}
