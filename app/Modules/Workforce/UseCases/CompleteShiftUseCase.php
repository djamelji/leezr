<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Shift;

class CompleteShiftUseCase
{
    /**
     * Mark a published shift as completed (planning lifecycle done).
     * This does NOT mean work was performed — TimeEntry is the source of actual work.
     * A shift can be completed without a TimeEntry; PlanningComparisonReadModel
     * will report missing_actual in that case.
     */
    public function execute(Shift $shift): Shift
    {
        // I7: Must be published
        if ($shift->status !== Shift::STATUS_PUBLISHED) {
            throw new \DomainException("Cannot complete shift: status is '{$shift->status}', expected 'published'.");
        }

        // Guard: end_at must have passed
        if ($shift->end_at->isFuture()) {
            throw new \DomainException('Cannot complete shift before its end time.');
        }

        $shift->transitionTo(Shift::STATUS_COMPLETED);
        $shift->save();

        app(AuditLogger::class)->logCompany(
            companyId: $shift->company_id,
            action: 'shift.completed',
            targetType: 'shift',
            targetId: (string) $shift->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.planning',
                    'employee_id' => $shift->employee_id,
                    'date' => $shift->date->toDateString(),
                ],
            ],
        );

        return $shift;
    }
}
