<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Core\Workforce\TimesheetPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LockTimesheetUseCase
{
    /**
     * Lock an approved timesheet for payroll consumption.
     * CORR #3: Requires workforce_payroll module activated + payroll_prepare permission.
     * Permission check is responsibility of the controller/middleware.
     * Module check is done here as business rule.
     */
    // B3-checkpoint (ADR-699) — atomicité : transaction + verrou + re-check d'état dans le verrou.
    public function execute(TimesheetPeriod $period, int $lockedBy): TimesheetPeriod
    {
        return DB::transaction(function () use ($period, $lockedBy) {
            $locked = TimesheetPeriod::withoutGlobalScopes()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TimesheetPeriod::STATUS_APPROVED) {
                throw new \DomainException("Cannot lock timesheet: status is '{$locked->status}', expected 'approved'.");
            }

            // CORR #3: Module workforce_payroll must be enabled
            $payrollActive = CompanyModuleActivationReason::where('company_id', $locked->company_id)
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
            $coreCompany = \App\Core\Models\Company::withoutGlobalScopes()->find($locked->company_id);
            if ($coreCompany) {
                $contract = Employee::withoutGlobalScopes()->find($locked->employee_id)?->currentContract;
                $resolver = app(WorkforceRuleResolver::class);
                $at = Carbon::parse($locked->period_end);
                $snapshot = $locked->policy_snapshot ?? [];
                $snapshot['workforce_rules'] = [
                    'captured_at' => now()->toIso8601String(),
                    'effective_at' => $at->toDateString(),
                    'work_time' => $resolver->resolveWorkTimeRules($coreCompany, $at, $contract),
                    'breaks' => $resolver->resolveBreakRules($coreCompany, $at, $contract),
                ];
                $locked->policy_snapshot = $snapshot;
            }

            $locked->transitionTo(TimesheetPeriod::STATUS_LOCKED);
            $locked->locked_at = now();
            $locked->locked_by = $lockedBy;
            $locked->save();

            app(AuditLogger::class)->logCompany(
                companyId: $locked->company_id,
                action: 'timesheet.locked',
                targetType: 'timesheet_period',
                targetId: (string) $locked->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.timesheet',
                        'employee_id' => $locked->employee_id,
                        'locked_by' => $lockedBy,
                    ],
                ],
            );

            return $locked;
        });
    }
}
