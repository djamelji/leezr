<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Facades\DB;

class ApproveTimesheetUseCase
{
    /**
     * Approve a submitted timesheet.
     * CORR #8: If error anomalies exist, approval_note is required.
     * B3-checkpoint (ADR-699) — ATOMICITÉ : transaction + verrou de ligne + re-check de l'état DANS le
     * verrou. Deux requêtes concurrentes (double approve, approve vs reject) se sérialisent → pas de
     * double transition ni de double événement d'audit. Aucune règle métier modifiée.
     */
    public function execute(
        TimesheetPeriod $period,
        int $approvedBy,
        ?string $approvalNote = null,
    ): TimesheetPeriod {
        return DB::transaction(function () use ($period, $approvedBy, $approvalNote) {
            $locked = TimesheetPeriod::withoutGlobalScopes()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TimesheetPeriod::STATUS_SUBMITTED) {
                throw new \DomainException("Cannot approve timesheet: status is '{$locked->status}', expected 'submitted'.");
            }

            // CORR #8: Error anomalies require acknowledgment
            $hasErrors = $locked->lines()->get()->contains(fn ($line) => $line->hasErrorAnomalies());
            if ($hasErrors && empty($approvalNote)) {
                throw new \DomainException(
                    'This timesheet has error-level anomalies. An approval note is required to acknowledge them.'
                );
            }

            $locked->transitionTo(TimesheetPeriod::STATUS_APPROVED);
            $locked->approved_by = $approvedBy;
            $locked->approved_at = now();
            $locked->approval_note = $approvalNote;
            $locked->save();

            app(AuditLogger::class)->logCompany(
                companyId: $locked->company_id,
                action: 'timesheet.approved',
                targetType: 'timesheet_period',
                targetId: (string) $locked->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.timesheet',
                        'employee_id' => $locked->employee_id,
                        'approved_by' => $approvedBy,
                        'had_error_anomalies' => $hasErrors,
                        'approval_note' => $approvalNote,
                    ],
                ],
            );

            return $locked;
        });
    }
}
