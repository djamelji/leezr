<?php

namespace App\Console\Commands;

use App\Core\Workforce\TimeEntry;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): reset an employee's time entries for a given day so the
 * self-service clock workflow (clock-in → break → resume → clock-out) is replayable WITHOUT a
 * global migrate:fresh. This only clears the target day's rows — it does NOT bypass any clock
 * business rule: the test still exercises the real clock-in/out use cases afterwards.
 *
 * NOT for production — guarded by environment.
 */
class E2EResetClockDayCommand extends Command
{
    protected $signature = 'e2e:reset-clock-day {--employee=3} {--date=}';

    protected $description = "Delete an employee's time entries for a day so the clock workflow is replayable (local/testing only)";

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $employeeId = (int) $this->option('employee');
        $date = $this->option('date') ?: today()->toDateString();

        $deleted = TimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('date', $date)
            ->delete();

        $this->info("Reset clock day for employee #{$employeeId} on {$date}: {$deleted} time entr(y/ies) removed.");

        return self::SUCCESS;
    }
}
