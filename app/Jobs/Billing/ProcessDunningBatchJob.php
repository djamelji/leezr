<?php

namespace App\Jobs\Billing;

use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Process a batch of overdue invoices for dunning retry.
 *
 * Dispatched by `billing:process-dunning --async`.
 * Each job receives a collection of invoice IDs and retries each one.
 */
class ProcessDunningBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * @param  Collection<int, int>  $invoiceIds
     */
    public function __construct(
        public readonly Collection $invoiceIds,
    ) {
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        Log::channel('billing')->info('[dunning-job] Processing batch', [
            'count' => $this->invoiceIds->count(),
        ]);

        foreach ($this->invoiceIds as $invoiceId) {
            $invoice = Invoice::find($invoiceId);

            if (! $invoice || $invoice->status !== 'overdue') {
                continue;
            }

            try {
                DunningEngine::retrySingleInvoice($invoice);
            } catch (\Throwable $e) {
                Log::channel('billing')->error('[dunning-job] Failed to retry invoice', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('[billing] ProcessDunningBatchJob permanently failed', [
            'invoice_ids' => $this->invoiceIds->toArray(),
            'error' => $exception->getMessage(),
        ]);
    }
}
