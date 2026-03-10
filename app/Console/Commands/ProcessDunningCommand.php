<?php

namespace App\Console\Commands;

use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Jobs\Billing\ProcessDunningBatchJob;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * Process overdue invoices — mark overdue, retry payments, apply failure actions.
 *
 * Designed for daily scheduling:
 *   $schedule->command('billing:process-dunning')->daily();
 *
 * Idempotent: safe to run multiple times per day.
 * Isolatable: only one instance can run at a time (cache lock).
 *
 * --async: dispatch jobs to the `billing` queue instead of processing inline.
 */
class ProcessDunningCommand extends Command implements Isolatable
{
    protected $signature = 'billing:process-dunning {--async}';

    protected $description = 'Process overdue invoices: retry payments and apply dunning policies';

    public function handle(): int
    {
        BillingJobHeartbeat::start('billing:process-dunning');

        if ($this->option('async')) {
            return $this->handleAsync();
        }

        $this->info('Processing overdue invoices...');

        $stats = DunningEngine::processOverdueInvoices();

        $this->info("Processed: {$stats['processed']}");
        $this->info("  Retried: {$stats['retried']}");
        $this->info("  Exhausted (uncollectible): {$stats['exhausted']}");
        $this->info("  Skipped: {$stats['skipped']}");

        if ($stats['exhausted'] > 0) {
            $this->warn("{$stats['exhausted']} invoice(s) marked uncollectible — failure action applied.");
        }

        BillingJobHeartbeat::finish('billing:process-dunning', $stats['exhausted'] > 0 ? 'failed' : 'ok', $stats);

        return self::SUCCESS;
    }

    private function handleAsync(): int
    {
        $this->info('Dispatching dunning jobs to queue...');

        $dispatched = 0;

        Invoice::where('status', 'overdue')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->select('id')
            ->chunkById(50, function ($batch) use (&$dispatched) {
                ProcessDunningBatchJob::dispatch($batch->pluck('id'));
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} batch job(s) to 'billing' queue.");

        BillingJobHeartbeat::finish('billing:process-dunning', 'dispatched', ['batches' => $dispatched]);

        return self::SUCCESS;
    }
}
