<?php

namespace App\Console\Commands;

use App\Console\Recipe\RecipeCommand;
use App\Core\Workforce\TimeEntry;

/**
 * E2E test fixture (LOCAL/TESTING only): reset an employee's time entries for a given day so the
 * self-service clock workflow (clock-in → break → resume → clock-out) is replayable WITHOUT a
 * global migrate:fresh. This only clears the target day's rows — it does NOT bypass any clock
 * business rule: the test still exercises the real clock-in/out use cases afterwards.
 *
 * NOT for production — guarded by environment.
 */
class E2EResetClockDayCommand extends RecipeCommand
{
    protected $signature = 'e2e:reset-clock-day {--employee=3} {--date=} {--clear-open-days : also remove EARLIER days left open (they block clock-in until a manager corrects them)}';

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
            ->whereDate('date', $date)
            ->delete();

        // ADR-829 (D-21) : a day left open earlier (clock_out null) blocks a new clock-in by design (ADR-779 banner) ;
        // the replay precondition is « no open day », so the spec asks for it explicitly. Breaks cascade with it.
        $stale = 0;
        if ($this->option('clear-open-days')) {
            $stale = TimeEntry::withoutGlobalScopes()->where('employee_id', $employeeId)->whereDate('date', '<', $date)
                ->whereNull('clock_out')->delete();
        }

        $this->info("Reset clock day for employee #{$employeeId} on {$date}: {$deleted} time entr(y/ies) removed, {$stale} earlier open day(s) removed.");

        return self::SUCCESS;
    }
}
