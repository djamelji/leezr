<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Support\Facades\DB;

class ReopenTimesheetUseCase
{
    /**
     * Reopen a rejected timesheet back to draft.
     * CORR #2: Deletes existing lines and regenerates from live sources.
     * The caller should invoke GenerateTimesheetUseCase after this.
     */
    public function execute(TimesheetPeriod $period): TimesheetPeriod
    {
        if ($period->status !== TimesheetPeriod::STATUS_REJECTED) {
            throw new \DomainException("Cannot reopen timesheet: status is '{$period->status}', expected 'rejected'.");
        }

        return DB::transaction(function () use ($period) {
            // Capture summary of old snapshot for audit
            $oldSummary = [
                'total_worked_minutes' => $period->total_worked_minutes,
                'total_overtime_minutes' => $period->total_overtime_minutes,
                'anomaly_count' => $period->anomaly_count,
                'line_count' => $period->lines()->count(),
                'rejected_at' => $period->rejected_at?->toIso8601String(),
                'rejection_reason' => $period->rejection_reason,
            ];

            // Delete all lines (I15: rejected → draft efface et régénère)
            $period->lines()->delete();

            // Reset to draft
            $period->transitionTo(TimesheetPeriod::STATUS_DRAFT);
            $period->submitted_by = null;
            $period->submitted_at = null;
            $period->approved_by = null;
            $period->approved_at = null;
            $period->approval_note = null;
            $period->rejected_by = null;
            $period->rejected_at = null;
            $period->rejection_reason = null;
            $period->policy_snapshot = null;
            $period->total_worked_minutes = 0;
            $period->total_break_minutes = 0;
            $period->total_overtime_minutes = 0;
            $period->total_planned_minutes = 0;
            $period->total_leave_days_hundredths = 0;
            $period->anomaly_count = 0;
            $period->save();

            app(AuditLogger::class)->logCompany(
                companyId: $period->company_id,
                action: 'timesheet.reopened',
                targetType: 'timesheet_period',
                targetId: (string) $period->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.timesheet',
                        'employee_id' => $period->employee_id,
                        'previous_snapshot_summary' => $oldSummary,
                    ],
                ],
            );

            return $period;
        });
    }
}
