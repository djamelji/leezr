<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TimeEntryReadModel
{
    public static function forEmployee(
        int $companyId,
        int $employeeId,
        Carbon $from,
        Carbon $to,
    ): Collection {
        return TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('breaks')
            ->orderBy('date')
            ->orderBy('clock_in')
            ->get();
    }

    public static function activeForCompany(int $companyId): Collection
    {
        return TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->active()
            ->with('employee:id,first_name,last_name,employee_number')
            ->get();
    }

    /**
     * Company-wide paginated listing of time entries with formatted clock times.
     */
    public static function forCompany(
        int $companyId,
        ?string $from = null,
        ?string $to = null,
        ?int $employeeId = null,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->with(['employee:id,first_name,last_name,employee_number', 'breaks']);

        if ($from) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('date')
            ->orderByDesc('clock_in')
            ->paginate($perPage);

        // Format clock times in each entry
        $paginator->getCollection()->transform(function (TimeEntry $entry) {
            $entry->setAttribute('clock_in_formatted', $entry->clock_in?->format('H:i'));
            $entry->setAttribute('clock_out_formatted', $entry->clock_out?->format('H:i'));
            $entry->setAttribute('break_count', $entry->breaks->count());
            $entry->setAttribute('total_break_display', self::formatMinutes($entry->total_break_minutes));
            $entry->setAttribute('total_worked_display', self::formatMinutes($entry->total_worked_minutes));

            // Detect anomalies for this entry
            $entry->setAttribute('anomalies', self::detectEntryAnomalies($entry));

            return $entry;
        });

        return $paginator;
    }

    /**
     * Detect anomalies across a date range for the company.
     */
    public static function detectAnomalies(
        int $companyId,
        string $from,
        string $to,
    ): Collection {
        $entries = TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->with(['employee:id,first_name,last_name', 'breaks'])
            ->orderBy('date')
            ->get();

        $anomalies = collect();

        foreach ($entries as $entry) {
            $entryAnomalies = self::detectEntryAnomalies($entry);
            foreach ($entryAnomalies as $anomaly) {
                $anomalies->push(array_merge($anomaly, [
                    'time_entry_id' => $entry->id,
                    'employee_id' => $entry->employee_id,
                    'employee_name' => $entry->employee?->first_name . ' ' . $entry->employee?->last_name,
                    'date' => $entry->date?->toDateString(),
                ]));
            }
        }

        return $anomalies;
    }

    /**
     * Detect anomalies on a single time entry.
     */
    private static function detectEntryAnomalies(TimeEntry $entry): array
    {
        $anomalies = [];

        // Missing clock_out on a working entry
        if ($entry->status === TimeEntry::STATUS_WORKING && ! $entry->clock_out) {
            $anomalies[] = [
                'type' => 'missing_clock_out',
                'severity' => 'warning',
                'message' => 'Clock in without clock out',
            ];
        }

        // Excessive hours (>10h without break)
        if ($entry->total_worked_minutes && $entry->total_worked_minutes > 600) {
            $anomalies[] = [
                'type' => 'excessive_hours',
                'severity' => 'warning',
                'message' => 'More than 10 hours worked',
            ];
        }

        // No break on long shift (>6h worked, <20min break — FR labour law)
        if ($entry->total_worked_minutes && $entry->total_worked_minutes > 360
            && ($entry->total_break_minutes ?? 0) < 20) {
            $anomalies[] = [
                'type' => 'missing_break',
                'severity' => 'warning',
                'message' => 'No sufficient break for shift >6h',
            ];
        }

        // Zero worked minutes on completed entry
        if ($entry->status === TimeEntry::STATUS_COMPLETED
            && ($entry->total_worked_minutes === 0 || $entry->total_worked_minutes === null)) {
            $anomalies[] = [
                'type' => 'zero_worked',
                'severity' => 'info',
                'message' => 'Zero worked minutes on completed entry',
            ];
        }

        return $anomalies;
    }

    public static function dailySummary(int $companyId, Carbon $date): Collection
    {
        return TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->where('date', $date->toDateString())
            ->completed()
            ->with('employee:id,first_name,last_name')
            ->get()
            ->map(fn (TimeEntry $e) => [
                'employee_id' => $e->employee_id,
                'employee_name' => $e->employee?->full_name,
                'clock_in' => $e->clock_in?->format('H:i'),
                'clock_out' => $e->clock_out?->format('H:i'),
                'worked_minutes' => $e->total_worked_minutes,
                'break_minutes' => $e->total_break_minutes,
            ]);
    }

    private static function formatMinutes(?int $minutes): string
    {
        if ($minutes === null || $minutes === 0) {
            return '0h00';
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%dh%02d', $h, $m);
    }

    /**
     * Self-service clock state of ONE person (ADR-779/780) : today's entry with its breaks, and an
     * entry left OPEN on a previous day (it blocks today's clock-in ; the correction is the manager's).
     *
     * @return array{today_clock: ?array, open_entry: ?array}
     */
    public static function selfClockState(int $companyId, int $employeeId): array
    {
        $today = TimeEntry::withoutCompanyScope()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('date', today()->toDateString())->with('breaks')->latest('clock_in')->first();
        $open = $today && $today->status !== TimeEntry::STATUS_COMPLETED ? null : TimeEntry::withoutCompanyScope()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('date', '<', today()->toDateString())->active()->latest('clock_in')->first();

        return [
            'open_entry' => $open ? ['id' => $open->id, 'date' => $open->date?->toDateString(), 'status' => $open->status] : null,
            'today_clock' => $today ? [
                'id' => $today->id,
                'status' => $today->status,
                'clock_in' => $today->clock_in?->toIso8601String(),
                'clock_out' => $today->clock_out?->toIso8601String(),
                'total_worked_minutes' => $today->total_worked_minutes,
                'total_break_minutes' => $today->total_break_minutes,
                'breaks' => $today->breaks->map(fn ($b) => [
                    'id' => $b->id,
                    'type' => $b->type,
                    'start_at' => $b->start_at?->toIso8601String(),
                    'end_at' => $b->end_at?->toIso8601String(),
                    'duration_minutes' => $b->duration_minutes,
                ]),
            ] : null,
        ];
    }

    /** The person's latest entry in an active state (optionally one precise status), or 404. */
    public static function latestActiveOrFail(int $companyId, int $employeeId, ?string $status = null): TimeEntry
    {
        $q = TimeEntry::withoutCompanyScope()->where('company_id', $companyId)->where('employee_id', $employeeId);
        $status ? $q->where('status', $status) : $q->active();

        return $q->latest('clock_in')->firstOrFail();
    }
}
