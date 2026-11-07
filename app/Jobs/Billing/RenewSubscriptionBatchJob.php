<?php

namespace App\Jobs\Billing;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Renew a batch of subscriptions.
 *
 * Dispatched by `billing:renew --async`.
 * Delegates to the billing:renew command with --ids filter.
 */
class RenewSubscriptionBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * @param  Collection<int, int>  $subscriptionIds
     */
    public function __construct(
        public readonly Collection $subscriptionIds,
    ) {
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        Log::channel('billing')->info('[renewal-job] Processing batch', [
            'count' => $this->subscriptionIds->count(),
        ]);

        // ADR-733.1 (P0-D.1) : orchestrateur GLOBAL explicite (la commande billing:renew balaye plusieurs
        // companies et re-filtre par-ligne). runGlobal documente l'intention cross-tenant (exempt du strict).
        CompanyContext::runGlobal(fn () => Artisan::call('billing:renew', [
            '--ids' => $this->subscriptionIds->implode(','),
        ]));
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('[billing] RenewSubscriptionBatchJob permanently failed', [
            'subscription_ids' => $this->subscriptionIds->toArray(),
            'error' => $exception->getMessage(),
        ]);
    }
}
