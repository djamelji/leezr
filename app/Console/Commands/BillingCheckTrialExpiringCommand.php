<?php

namespace App\Console\Commands;

use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Notifications\Billing\TrialExpiring;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-272/341: Check for subscriptions with trials expiring within configurable days.
 *
 * Dispatches TrialExpiring notification to company owner.
 * Idempotent: stores notification flag in subscription metadata.
 */
class BillingCheckTrialExpiringCommand extends Command implements Isolatable
{
    protected $signature = 'billing:check-trial-expiring {--dry-run}';

    protected $description = 'Notify company owners about expiring trials';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $days = PlatformBillingPolicy::instance()->trial_expiry_notification_days ?? 3;
        $threshold = now()->addDays($days);

        $subscriptions = Subscription::where('status', 'trialing')
            ->where('is_current', 1)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $threshold)
            ->where('trial_ends_at', '>', now())
            ->with('company')
            ->get()
            ->filter(function ($sub) {
                $meta = $sub->metadata ?? [];

                return empty($meta['trial_expiry_notified']);
            });

        $this->info("Found {$subscriptions->count()} trial(s) expiring within {$days} days.");
        $notified = 0;

        foreach ($subscriptions as $subscription) {
            $company = $subscription->company;

            if (! $company) {
                continue;
            }

            $owner = $company->owner();

            if (! $owner) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would notify: company #{$company->id} — trial ends {$subscription->trial_ends_at}");

                continue;
            }

            try {
                $owner->notify(new TrialExpiring($subscription));

                // Mark as notified
                $meta = $subscription->metadata ?? [];
                $meta['trial_expiry_notified'] = now()->toDateString();
                $subscription->update(['metadata' => $meta]);

                $notified++;
            } catch (\Throwable $e) {
                Log::warning('[billing:check-trial-expiring] Failed to notify', [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Failed: subscription #{$subscription->id} — {$e->getMessage()}");
            }
        }

        $this->info("Notified: {$notified}");
        Log::channel('billing')->info('billing:check-trial-expiring finished', [
            'total' => $subscriptions->count(),
            'notified' => $notified,
        ]);

        return self::SUCCESS;
    }
}
