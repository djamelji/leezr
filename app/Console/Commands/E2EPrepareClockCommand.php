<?php

namespace App\Console\Commands;

use App\Core\Workforce\TimeEntry;
use Illuminate\Console\Command;

/**
 * E2E test fixture (LOCAL/TESTING only): put an employee into a WORKING clock state with a
 * clock_in set N minutes in the past, so the navbar/worked-time counter must display ~N minutes
 * and KEEP it across refresh (server timestamp = source of truth). Proves the counter does not
 * reset to zero on reload. Idempotent: clears today's entries first. NOT for production.
 */
class E2EPrepareClockCommand extends Command
{
    protected $signature = 'e2e:prepare-clock {--employee=3} {--company=1} {--minutes-ago=25}';

    protected $description = 'Create a WORKING time entry with clock_in N minutes ago (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $employeeId = (int) $this->option('employee');
        $companyId = (int) $this->option('company');
        $minutesAgo = (int) $this->option('minutes-ago');

        TimeEntry::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('date', today()->toDateString())
            ->delete();

        $entry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'date' => today()->toDateString(),
            'clock_in' => now()->subMinutes($minutesAgo),
            'status' => TimeEntry::STATUS_WORKING,
            'total_worked_minutes' => 0,
            'total_break_minutes' => 0,
            'source' => 'manual',
        ]);

        $this->info("Prepared WORKING entry #{$entry->id} for employee #{$employeeId}, clock_in {$minutesAgo} min ago.");

        return self::SUCCESS;
    }
}
