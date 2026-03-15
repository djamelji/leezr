<?php

namespace App\Console\Commands;

use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Notifications\NotificationDispatcher;
use App\Notifications\Billing\PaymentMethodExpiring;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-272: Check for payment methods expiring within 30 days.
 *
 * Dispatches PaymentMethodExpiring notification to company owner.
 * Idempotent: marks notified profiles via metadata flag to avoid duplicates.
 */
class BillingCheckExpiringCardsCommand extends Command implements Isolatable
{
    protected $signature = 'billing:check-expiring-cards {--dry-run}';

    protected $description = 'Notify company owners about expiring payment methods';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $now = now();
        $currentMonth = (int) $now->format('n');
        $currentYear = (int) $now->format('Y');

        // Compute the 30-day window: current month/year + next month/year
        $targetPeriods = [
            ['month' => $currentMonth, 'year' => $currentYear],
        ];

        $nextMonth = $now->copy()->addMonth();
        $targetPeriods[] = [
            'month' => (int) $nextMonth->format('n'),
            'year' => (int) $nextMonth->format('Y'),
        ];

        $profiles = CompanyPaymentProfile::with('company')
            ->get()
            ->filter(function ($profile) use ($targetPeriods) {
                $meta = $profile->metadata ?? [];
                $expMonth = $meta['exp_month'] ?? null;
                $expYear = $meta['exp_year'] ?? null;

                if (! $expMonth || ! $expYear) {
                    return false;
                }

                // Already notified for this expiry cycle
                if (! empty($meta['expiry_notified'])) {
                    return false;
                }

                foreach ($targetPeriods as $period) {
                    if ((int) $expMonth === $period['month'] && (int) $expYear === $period['year']) {
                        return true;
                    }
                }

                return false;
            });

        $this->info("Found {$profiles->count()} expiring payment method(s).");
        $notified = 0;

        foreach ($profiles as $profile) {
            $company = $profile->company;

            if (! $company) {
                continue;
            }

            $owner = $company->owner();

            if (! $owner) {
                continue;
            }

            $meta = $profile->metadata ?? [];

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would notify: company #{$company->id} — {$meta['brand']} ending {$meta['last4']} expires {$meta['exp_month']}/{$meta['exp_year']}");

                continue;
            }

            try {
                NotificationDispatcher::send(
                    topicKey: 'billing.payment_method_expiring',
                    recipients: [$owner],
                    payload: [
                        'brand' => $meta['brand'] ?? null,
                        'last4' => $meta['last4'] ?? null,
                        'exp_month' => $meta['exp_month'] ?? null,
                        'exp_year' => $meta['exp_year'] ?? null,
                    ],
                    company: $company,
                    mailNotification: new PaymentMethodExpiring($profile),
                );

                // Mark as notified to ensure idempotency
                $meta['expiry_notified'] = $now->toDateString();
                $profile->update(['metadata' => $meta]);

                $notified++;
            } catch (\Throwable $e) {
                Log::warning('[billing:check-expiring-cards] Failed to notify', [
                    'company_id' => $company->id,
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Failed: profile #{$profile->id} — {$e->getMessage()}");
            }
        }

        $this->info("Notified: {$notified}");
        Log::channel('billing')->info('billing:check-expiring-cards finished', [
            'total' => $profiles->count(),
            'notified' => $notified,
        ]);

        return self::SUCCESS;
    }
}
