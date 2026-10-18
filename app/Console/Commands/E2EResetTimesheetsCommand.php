<?php

namespace App\Console\Commands;

use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): delete an employee's timesheet period(s) overlapping a
 * date range so the timesheet workflow (generate → submit → approve → lock) is replayable without
 * a global migrate:fresh. Only clears the test window — it does NOT bypass any timesheet rule;
 * the test still drives the real generate/submit/approve/lock use cases afterwards.
 *
 * NOT for production — guarded by environment.
 */
class E2EResetTimesheetsCommand extends Command
{
    protected $signature = 'e2e:reset-timesheets {--employee=3} {--from=} {--to=}';

    protected $description = "Delete an employee's timesheet periods overlapping a range (local/testing only)";

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $employeeId = (int) $this->option('employee');
        $from = $this->option('from') ?: today()->startOfMonth()->toDateString();
        $to = $this->option('to') ?: today()->endOfMonth()->toDateString();

        $periods = TimesheetPeriod::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('period_start', '<=', $to)
            ->where('period_end', '>=', $from)
            ->get();

        $count = 0;
        foreach ($periods as $period) {
            $period->lines()->delete();
            $period->delete();
            $count++;
        }

        $this->info("Reset timesheets for employee #{$employeeId} in [{$from} … {$to}]: {$count} period(s) removed.");

        return self::SUCCESS;
    }
}
