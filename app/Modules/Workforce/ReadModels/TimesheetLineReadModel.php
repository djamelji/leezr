<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\TimesheetLine;
use Illuminate\Support\Collection;

class TimesheetLineReadModel
{
    public static function forPeriod(int $timesheetPeriodId): Collection
    {
        return TimesheetLine::withoutGlobalScopes()
            ->where('timesheet_period_id', $timesheetPeriodId)
            ->orderBy('date')
            ->get();
    }

    public static function dailyDetail(int $companyId, int $employeeId, string $date): ?TimesheetLine
    {
        return TimesheetLine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('date', $date)
            ->with('timesheetPeriod')
            ->first();
    }
}
