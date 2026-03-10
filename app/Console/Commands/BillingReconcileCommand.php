<?php

namespace App\Console\Commands;

use App\Console\Concerns\HasCorrelationId;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\ReconciliationEngine;
use App\Jobs\Billing\ReconcileCompanyJob;
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
 *
 * --async: dispatch one job per company to the `billing-slow` queue.
 */
class BillingReconcileCommand extends Command implements Isolatable
{
    use HasCorrelationId;

    protected $signature = 'billing:reconcile {--company= : Limit to a specific company ID} {--dry-run : Skip audit logging and mutations} {--repair : Attempt auto-repair of safe drift types} {--async}';

    protected $description = 'Detect payment drift between Stripe and local database';

    public function handle(): int
    {
        $this->initCorrelationId();
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

        if ($this->option('async')) {
            return $this->handleAsync($companyId, $autoRepair);
        }

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

    private function handleAsync(?int $companyId, bool $autoRepair): int
    {
        $this->info('Dispatching reconciliation jobs to queue...');

        $query = CompanyPaymentCustomer::where('provider_key', 'stripe');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $dispatched = 0;

        $query->select(['id', 'company_id'])->distinct('company_id')->chunkById(50, function ($batch) use (&$dispatched, $autoRepair) {
            foreach ($batch as $customer) {
                ReconcileCompanyJob::dispatch($customer->company_id, $autoRepair);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} reconciliation job(s) to 'billing-slow' queue.");

        BillingJobHeartbeat::finish('billing:reconcile', 'dispatched', ['jobs' => $dispatched]);

        return self::SUCCESS;
    }
}
