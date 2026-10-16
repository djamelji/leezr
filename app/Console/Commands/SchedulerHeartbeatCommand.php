<?php

namespace App\Console\Commands;

use App\Core\Automation\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Records a scheduler liveness heartbeat. Scheduled every minute so a stale heartbeat
 * reliably means the cron (schedule:run) stopped — independent of business tasks.
 * Intentionally lightweight and NOT instrumented into ScheduledTaskRun (no history
 * bloat); excluded from the cockpit registry by documented convention.
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Record a scheduler liveness heartbeat (every minute)';

    public function handle(): int
    {
        SchedulerHeartbeat::pulse();

        return self::SUCCESS;
    }
}
