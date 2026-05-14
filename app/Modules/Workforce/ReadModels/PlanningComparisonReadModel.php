<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class PlanningComparisonReadModel
{
    /**
     * Compare planned shifts vs actual time entries for an employee over a date range.
     *
     * Returns array of daily comparisons:
     * [
     *   ['date' => '2026-05-04', 'planned_minutes' => 450, 'actual_minutes' => 420,
     *    'delta_minutes' => -30, 'shift_ids' => [1,2], 'time_entry_ids' => [5],
     *    'missing_actual' => false],
     * ]
     */
    public static function plannedVsActual(
        int $companyId,
        int $employeeId,
        Carbon $from,
        Carbon $to,
    ): array {
        // Load all non-cancelled shifts in range
        $shifts = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->notCancelled()
            ->get();

        // Load all completed time entries in range
        $timeEntries = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->completed()
            ->get();

        // Group by date
        $shiftsByDate = $shifts->groupBy(fn ($s) => $s->date->toDateString());
        $entriesByDate = $timeEntries->groupBy(fn ($e) => $e->date->toDateString());

        $result = [];
        $dates = CarbonPeriod::create($from, $to);

        foreach ($dates as $dateCarbon) {
            $dateString = $dateCarbon->toDateString();
            $dayShifts = $shiftsByDate->get($dateString, collect());
            $dayEntries = $entriesByDate->get($dateString, collect());

            // Skip days with no planned and no actual
            if ($dayShifts->isEmpty() && $dayEntries->isEmpty()) {
                continue;
            }

            $plannedMinutes = $dayShifts->sum(fn ($s) => $s->plannedWorkMinutes());
            $actualMinutes = $dayEntries->sum(fn ($e) => $e->total_worked_minutes ?? 0);

            $hasCompletedShifts = $dayShifts->contains(fn ($s) => $s->status === Shift::STATUS_COMPLETED);

            $result[] = [
                'date' => $dateString,
                'planned_minutes' => $plannedMinutes,
                'actual_minutes' => $actualMinutes,
                'delta_minutes' => $actualMinutes - $plannedMinutes,
                'shift_ids' => $dayShifts->pluck('id')->values()->toArray(),
                'time_entry_ids' => $dayEntries->pluck('id')->values()->toArray(),
                'missing_actual' => $hasCompletedShifts && $dayEntries->isEmpty(),
            ];
        }

        return $result;
    }
}
