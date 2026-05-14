<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
}
