<?php

namespace App\Console\Commands;

use App\Core\Billing\Subscription;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-360: Auto-expire trials whose trial_ends_at has passed.
 *
 * Transitions trialing subscriptions to expired status when trial period ends.
 * Idempotent: only processes subscriptions still in trialing state with is_current=1.
 *
 * Trial lifecycle distinction (audit 2026-10): this command ends FREE trials only
 * (no collectible invoice owed). A PAID trial whose conversion invoice is still
 * open/overdue is NOT expired here — it belongs to dunning (DunningTransitioner
 * moves it trialing → past_due → retries → suspend/cancel), so we never orphan an
 * outstanding invoice by flipping its subscription to 'expired'.
 */
class BillingExpireTrialsCommand extends Command implements Isolatable
{
    protected $signature = 'billing:expire-trials {--dry-run}';

    protected $description = 'Expire subscriptions whose trial period has ended';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $subscriptions = Subscription::where('status', 'trialing')
            ->where('is_current', 1)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            // Skip PAID trials with an outstanding conversion invoice — dunning owns them.
            ->whereDoesntHave('invoices', fn ($q) => $q->whereIn('status', ['open', 'overdue']))
            ->with('company')
            ->get();

        $this->info("Found {$subscriptions->count()} expired trial(s).");
        $expired = 0;

        foreach ($subscriptions as $subscription) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Would expire: subscription #{$subscription->id} — trial ended {$subscription->trial_ends_at}");

                continue;
            }

            try {
                $subscription->markExpired();
                $expired++;

                Log::channel('billing')->info('Trial expired', [
                    'subscription_id' => $subscription->id,
                    'company_id' => $subscription->company_id,
                    'plan_key' => $subscription->plan_key,
                    'trial_ends_at' => $subscription->trial_ends_at,
                ]);
            } catch (\Throwable $e) {
                Log::channel('billing')->error('Failed to expire trial', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Failed: subscription #{$subscription->id} — {$e->getMessage()}");
            }
        }

        $this->info("Expired: {$expired}");
        Log::channel('billing')->info('billing:expire-trials finished', [
            'total' => $subscriptions->count(),
            'expired' => $expired,
        ]);

        return self::SUCCESS;
    }
}
