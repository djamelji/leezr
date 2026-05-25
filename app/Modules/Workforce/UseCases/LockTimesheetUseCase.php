<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\TimesheetPeriod;

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
