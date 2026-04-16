<?php

namespace App\Console\Commands;

use App\Core\Automation\WorkflowRule;
use Illuminate\Console\Command;

/**
 * ADR-437: Reset daily execution counters for workflow rules.
 * Runs at midnight via scheduler.
 */
class WorkflowResetDailyCountersCommand extends Command
{
    protected $signature = 'workflow:reset-daily-counters';

    protected $description = 'Reset daily execution counters for all workflow rules';

    public function handle(): int
    {
        $updated = WorkflowRule::withoutCompanyScope()
            ->where('executions_today', '>', 0)
            ->update(['executions_today' => 0]);

        $this->info("Reset {$updated} workflow rule(s) daily counters.");

        return self::SUCCESS;
    }
}
