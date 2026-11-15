<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\Support\WeekStartResolver;
use Carbon\CarbonImmutable;

/**
 * ADR-787 — the employee's OWN hours, read from the existing time data (no second time engine) :
 *
 *  - worked = net minutes (breaks already deducted by TimeEntry) of COMPLETED entries, by work day
 *    (`TimeEntry.date`), inside the bounds. A running entry is reported apart, never counted.
 *    Corrections update the entry in place : one entry per day, no double count.
 *  - pending / validated = timesheet lines of periods SUBMITTED / APPROVED-LOCKED, same bounds.
 *    Never presented as paid hours.
 *  - week = company week start (WeekStartResolver), « today » in the company (market) timezone.
 *  - period = the payroll run covering today when one exists, otherwise the calendar month,
 *    explicitly labelled as such (`kind`).
 */
final class PersonalHoursReadModel
{
    public static function forEmployee(Company $company, Employee $employee, ?CarbonImmutable $now = null): array
    {
        $tz = ($company->market_key ? Market::where('key', $company->market_key)->value('timezone') : null) ?: config('app.timezone');
        $today = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();

        $weekStartDay = WeekStartResolver::resolve($company);
        $weekStart = $today->subDays(($today->dayOfWeek - $weekStartDay + 7) % 7);
        $weekEnd = $weekStart->addDays(6);

        $run = PayrollRun::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereDate('period_start', '<=', $today->toDateString())->whereDate('period_end', '>=', $today->toDateString())
            ->orderByDesc('period_start')->first(['period_start', 'period_end']);
        [$kind, $from, $to] = $run
            ? ['payroll', CarbonImmutable::parse($run->period_start, $tz)->startOfDay(), CarbonImmutable::parse($run->period_end, $tz)->startOfDay()]
            : ['month', $today->startOfMonth(), $today->endOfMonth()->startOfDay()];

        $running = TimeEntry::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereIn('status', [TimeEntry::STATUS_WORKING, TimeEntry::STATUS_ON_BREAK])->orderByDesc('clock_in')->first(['clock_in', 'status', 'date']);

        return [
            'timezone' => $tz,
            'week_start_day' => $weekStartDay,
            'week' => self::range($company->id, $employee->id, $weekStart, $weekEnd),
            'period' => ['kind' => $kind] + self::range($company->id, $employee->id, $from, $to),
            'in_progress' => $running ? ['since' => $running->clock_in?->toIso8601String(), 'status' => $running->status] : null,
        ];
    }

    private static function range(int $companyId, int $employeeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$a, $b] = [$from->toDateString(), $to->toDateString()];

        $worked = (int) TimeEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('status', TimeEntry::STATUS_COMPLETED)->whereDate('date', '>=', $a)->whereDate('date', '<=', $b)
            ->sum('total_worked_minutes');

        $byStatus = TimesheetLine::withoutGlobalScopes()
            ->join('workforce_timesheet_periods as p', 'p.id', '=', 'workforce_timesheet_lines.timesheet_period_id')
            ->where('workforce_timesheet_lines.company_id', $companyId)->where('workforce_timesheet_lines.employee_id', $employeeId)
            ->whereDate('workforce_timesheet_lines.date', '>=', $a)->whereDate('workforce_timesheet_lines.date', '<=', $b)
            ->selectRaw('p.status as status, SUM(workforce_timesheet_lines.worked_minutes) as minutes')
            ->groupBy('p.status')->pluck('minutes', 'status');

        return [
            'from' => $a,
            'to' => $b,
            'worked_minutes' => $worked,
            'pending_minutes' => (int) ($byStatus[TimesheetPeriod::STATUS_SUBMITTED] ?? 0),
            'validated_minutes' => (int) (($byStatus[TimesheetPeriod::STATUS_APPROVED] ?? 0) + ($byStatus[TimesheetPeriod::STATUS_LOCKED] ?? 0)),
        ];
    }
}
