<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\ModuleRegistry;
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
        $moduleRegistry = app(ModuleRegistry::class);
        if (! $moduleRegistry->isEnabled($period->company_id, 'workforce_payroll')) {
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
