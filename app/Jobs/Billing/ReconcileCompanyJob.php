<?php

namespace App\Jobs\Billing;

use App\Core\Billing\ReconciliationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile a single company's payments with Stripe.
 *
 * Dispatched by `billing:reconcile --async`.
 * Rate limited to avoid hitting Stripe API limits.
 */
class ReconcileCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $companyId,
        public readonly bool $autoRepair = false,
    ) {
        $this->onQueue('billing-slow');
    }

    public function middleware(): array
    {
        return [new RateLimited('billing-reconcile')];
    }

    public function handle(): void
    {
        Log::channel('billing')->info('[reconcile-job] Processing company', [
            'company_id' => $this->companyId,
        ]);

        try {
            ReconciliationEngine::reconcile(
                companyId: $this->companyId,
                dryRun: false,
                autoRepair: $this->autoRepair,
            );
        } catch (\Throwable $e) {
            Log::channel('billing')->error('[reconcile-job] Failed for company', [
                'company_id' => $this->companyId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('[billing] ReconcileCompanyJob permanently failed', [
            'company_id' => $this->companyId,
            'error' => $exception->getMessage(),
        ]);
    }
}
