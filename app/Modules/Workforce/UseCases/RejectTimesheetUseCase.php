<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Facades\DB;

class RejectTimesheetUseCase
{
    // B3-checkpoint (ADR-699) — atomicité : transaction + verrou + re-check d'état dans le verrou.
    public function execute(
        TimesheetPeriod $period,
        int $rejectedBy,
        string $rejectionReason,
    ): TimesheetPeriod {
        if (empty(trim($rejectionReason))) {
            throw new \DomainException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($period, $rejectedBy, $rejectionReason) {
            $locked = TimesheetPeriod::withoutGlobalScopes()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TimesheetPeriod::STATUS_SUBMITTED) {
                throw new \DomainException("Cannot reject timesheet: status is '{$locked->status}', expected 'submitted'.");
            }

            $locked->transitionTo(TimesheetPeriod::STATUS_REJECTED);
            $locked->rejected_by = $rejectedBy;
            $locked->rejected_at = now();
            $locked->rejection_reason = $rejectionReason;
            $locked->save();

            app(AuditLogger::class)->logCompany(
                companyId: $locked->company_id,
                action: 'timesheet.rejected',
                targetType: 'timesheet_period',
                targetId: (string) $locked->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.timesheet',
                        'employee_id' => $locked->employee_id,
                        'rejected_by' => $rejectedBy,
                        'rejection_reason' => $rejectionReason,
                    ],
                ],
            );

            return $locked;
        });
    }
}
