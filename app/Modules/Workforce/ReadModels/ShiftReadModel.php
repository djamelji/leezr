<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\Shift;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ShiftReadModel
{
    public static function forEmployee(
        int $companyId,
        int $employeeId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with(['workLocation', 'scheduleTemplate']);

        if ($from) {
            $query->where('date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->where('date', '<=', $to->toDateString());
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('date')->orderBy('start_at')->paginate($perPage);
    }

    public static function forDate(int $companyId, string $date): Collection
    {
        return Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('date', $date)
            ->notCancelled()
            ->with(['employee', 'workLocation'])
            ->orderBy('start_at')
            ->get();
    }

    public static function forWeek(int $companyId, Carbon $weekStart): Collection
    {
        $weekEnd = $weekStart->copy()->addDays(6);

        return Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->notCancelled()
            ->with(['employee', 'workLocation'])
            ->orderBy('date')
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Return shifts in FullCalendar-compatible format.
     */
    public static function calendar(int $companyId, Carbon $from, Carbon $to): Collection
    {
        return Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->notCancelled()
            ->with(['employee', 'workLocation'])
            ->orderBy('start_at')
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'title' => $shift->employee->full_name,
                'start' => $shift->start_at->toIso8601String(),
                'end' => $shift->end_at->toIso8601String(),
                'allDay' => false,
                'color' => $shift->template_snapshot['color'] ?? null,
                'extendedProps' => [
                    'employee_id' => $shift->employee_id,
                    'employee_name' => $shift->employee->full_name,
                    'status' => $shift->status,
                    'location' => $shift->workLocation?->name,
                    'location_type' => $shift->workLocation?->type,
                    'planned_minutes' => $shift->plannedWorkMinutes(),
                    'date' => $shift->date->toDateString(),
                    'timezone' => $shift->timezone,
                    'template_name' => $shift->template_snapshot['template_name'] ?? null,
                ],
            ]);
    }

    public static function statistics(int $companyId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId);

        if ($from) {
            $query->where('date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->where('date', '<=', $to->toDateString());
        }

        $shifts = $query->get();

        $byStatus = $shifts->groupBy('status')->map(fn ($group) => [
            'count' => $group->count(),
            'total_planned_minutes' => $group->sum(fn ($s) => $s->plannedWorkMinutes()),
        ]);

        return [
            'total' => $shifts->count(),
            'by_status' => $byStatus->toArray(),
            'total_planned_minutes' => $shifts->sum(fn ($s) => $s->plannedWorkMinutes()),
        ];
    }
}
