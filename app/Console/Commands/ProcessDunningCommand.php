<?php

namespace App\Console\Commands;

use App\Core\Billing\DunningEngine;
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
 */
class ProcessDunningCommand extends Command implements Isolatable
{
    protected $signature = 'billing:process-dunning';

    protected $description = 'Process overdue invoices: retry payments and apply dunning policies';

    public function handle(): int
    {
        $this->info('Processing overdue invoices...');

        $stats = DunningEngine::processOverdueInvoices();

        $this->info("Processed: {$stats['processed']}");
        $this->info("  Retried: {$stats['retried']}");
        $this->info("  Exhausted (uncollectible): {$stats['exhausted']}");
        $this->info("  Skipped: {$stats['skipped']}");

        if ($stats['exhausted'] > 0) {
            $this->warn("{$stats['exhausted']} invoice(s) marked uncollectible — failure action applied.");
        }

        return self::SUCCESS;
    }
}
