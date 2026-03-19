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
