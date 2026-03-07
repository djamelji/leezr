<?php

namespace App\Console\Commands;

use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\ReconciliationEngine;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * Detect payment drift between Stripe and local database.
 *
 * Designed for weekly scheduling:
 *   $schedule->command('billing:reconcile')->weekly();
 *
 * Detection + optional auto-repair (ADR-140/141).
 * Isolatable: only one instance can run at a time (cache lock).
 */
class BillingReconcileCommand extends Command implements Isolatable
{
    protected $signature = 'billing:reconcile {--company= : Limit to a specific company ID} {--dry-run : Skip audit logging and mutations} {--repair : Attempt auto-repair of safe drift types}';

    protected $description = 'Detect payment drift between Stripe and local database';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $dryRun = (bool) $this->option('dry-run');
        $autoRepair = (bool) $this->option('repair');

        if ($dryRun) {
            $this->info('Dry run — no audit logs or mutations will be created.');
        }

        if ($autoRepair && ! config('billing.auto_repair.enabled')) {
            $this->warn('Auto-repair requested but billing.auto_repair.enabled is false. Skipping repairs.');
            $autoRepair = false;
        }

        BillingJobHeartbeat::start('billing:reconcile');

        $this->info('Running billing reconciliation...');

        $result = ReconciliationEngine::reconcile($companyId, $dryRun, $autoRepair);

        $this->info("Total drifts detected: {$result['summary']['total']}");

        foreach ($result['summary']['by_type'] as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        if ($result['summary']['total'] === 0) {
            $this->info('No drift detected — all clear.');
        } else {
            $this->warn("{$result['summary']['total']} drift(s) detected — review audit logs.");
        }

        // Report repairs if auto-repair was executed
        if (isset($result['repairs'])) {
            $repairs = $result['repairs'];
            $this->newLine();
            $this->info("Auto-repair results:");
            $this->line("  Repaired: " . count($repairs['repaired']));
            $this->line("  Skipped:  " . count($repairs['skipped']));
            $this->line("  Errors:   " . count($repairs['errors']));

            if (count($repairs['repaired']) > 0) {
                $this->info("  Correlation ID: {$repairs['correlation_id']}");
            }
        }

        BillingJobHeartbeat::finish('billing:reconcile', $result['summary']['total'] > 0 ? 'drift' : 'ok', $result['summary']);

        return self::SUCCESS;
    }
}
