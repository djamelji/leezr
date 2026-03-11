<?php

namespace App\Console\Commands;

use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\ScheduledDebit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-328 S2: Process due scheduled SEPA debits.
 *
 * Runs daily (06:00). For each pending debit where debit_date <= today:
 *   1. Mark processing
 *   2. Attempt payment via PaymentGatewayManager
 *   3. On success: mark collected, update invoice status
 *   4. On failure: mark failed, DunningEngine takes over
 */
class BillingCollectScheduledCommand extends Command implements Isolatable
{
    protected $signature = 'billing:collect-scheduled {--dry-run}';

    protected $description = 'Process due scheduled SEPA debits';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $dueDebits = ScheduledDebit::with(['invoice', 'invoice.company', 'paymentProfile'])
            ->pending()
            ->due()
            ->get();

        $stats = ['processed' => 0, 'collected' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($dueDebits as $debit) {
            $invoice = $debit->invoice;

            if (! $invoice || $invoice->status === 'paid' || $invoice->status === 'void') {
                $debit->update(['status' => 'cancelled']);
                $stats['skipped']++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Would collect: company #{$debit->company_id}, invoice #{$invoice->id}, amount {$debit->amount}");
                $stats['processed']++;

                continue;
            }

            $debit->update(['status' => 'processing']);

            try {
                $adapter = app(PaymentGatewayManager::class)->driver();
                $result = $adapter->collectInvoice($invoice, $invoice->company);

                if (($result['status'] ?? '') === 'succeeded') {
                    $debit->update([
                        'status' => 'collected',
                        'processed_at' => now(),
                    ]);
                    $stats['collected']++;

                    Log::channel('billing')->info('Scheduled debit collected', [
                        'debit_id' => $debit->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $debit->amount,
                    ]);
                } else {
                    $failureReason = $result['failure_reason'] ?? $result['error'] ?? 'unknown';
                    $debit->update([
                        'status' => 'failed',
                        'processed_at' => now(),
                        'failure_reason' => $failureReason,
                    ]);
                    $stats['failed']++;

                    Log::channel('billing')->warning('Scheduled debit failed — entering dunning', [
                        'debit_id' => $debit->id,
                        'invoice_id' => $invoice->id,
                        'failure_reason' => $failureReason,
                    ]);
                }
            } catch (\Throwable $e) {
                $debit->update([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'failure_reason' => $e->getMessage(),
                ]);
                $stats['failed']++;

                Log::error('[billing:collect-scheduled] Exception processing debit', [
                    'debit_id' => $debit->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $stats['processed']++;
        }

        $this->info("Scheduled debits: {$stats['processed']} processed, {$stats['collected']} collected, {$stats['failed']} failed, {$stats['skipped']} skipped");

        return self::SUCCESS;
    }
}
