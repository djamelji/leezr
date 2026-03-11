<?php

namespace App\Console\Commands;

use App\Console\Concerns\HasCorrelationId;
use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingCoupon;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\Plan;
use App\Notifications\Billing\PaymentReceived;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-renew expired subscriptions — ADR-223.
 *
 * Pipeline:
 *   1. Find subscriptions with current_period_end <= now() (active/trialing, is_current=1)
 *   2. Create renewal invoice (inside transaction)
 *   3. Attempt off-session Stripe payment (outside transaction)
 *   4. On success: extend subscription period (inside transaction)
 *   5. On failure: invoice stays open → dunning will handle retries
 *
 * Designed for daily scheduling. Idempotent: safe to run multiple times.
 * The query current_period_end <= now() guarantees catch-up if cron runs late.
 */
class BillingRenewCommand extends Command implements Isolatable
{
    use HasCorrelationId;

    protected $signature = 'billing:renew {--dry-run} {--async} {--ids= : Comma-separated subscription IDs (used by queue jobs)}';

    protected $description = 'Auto-renew subscriptions that have reached their period end';

    public function handle(): int
    {
        $this->initCorrelationId();
        BillingJobHeartbeat::start('billing:renew');

        $dryRun = $this->option('dry-run');

        if ($this->option('async')) {
            return $this->handleAsync();
        }

        $stats = ['eligible' => 0, 'renewed' => 0, 'invoiced' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0];

        // Phase 0: Handle subscriptions marked for cancellation at period end
        $this->processCancellations($dryRun, $stats);

        $query = Subscription::whereIn('status', ['active', 'trialing'])
            ->where('is_current', 1)
            ->where('current_period_end', '<=', now())
            ->where(fn ($q) => $q->where('cancel_at_period_end', false)->orWhereNull('cancel_at_period_end'))
            ->with(['company', 'company.market']);

        // ADR-318: Filter by specific IDs when called from queue job
        if ($ids = $this->option('ids')) {
            $idList = array_map('intval', explode(',', $ids));
            $query->whereIn('id', $idList);
        }

        $stats['eligible'] = $query->count();
        $this->info("Found {$stats['eligible']} subscription(s) eligible for renewal.");
        Log::channel('billing')->info('billing:renew started', ['eligible' => $stats['eligible']]);

        if ($dryRun) {
            $query->chunkById(50, function ($batch) {
                foreach ($batch as $sub) {
                    $this->line("  [DRY-RUN] Company #{$sub->company_id} — plan={$sub->plan_key} interval={$sub->interval} expired={$sub->current_period_end}");
                }
            });

            return self::SUCCESS;
        }

        $query->chunkById(50, function ($batch) use (&$stats) {
            foreach ($batch as $subscription) {
                try {
                    $result = $this->renewSubscription($subscription);

                    if ($result === 'renewed') {
                        $stats['renewed']++;
                    } elseif ($result === 'invoiced') {
                        $stats['invoiced']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    Log::channel('billing')->error('[billing:renew] Failed to renew subscription', [
                        'subscription_id' => $subscription->id,
                        'company_id' => $subscription->company_id,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("  Failed: subscription #{$subscription->id} — {$e->getMessage()}");
                }
            }
        });

        $this->info("Cancelled (end of period): {$stats['cancelled']}");
        $this->info("Renewed: {$stats['renewed']}");
        $this->info("Invoiced (payment pending): {$stats['invoiced']}");
        $this->info("Failed: {$stats['failed']}");
        $this->info("Skipped: {$stats['skipped']}");

        Log::channel('billing')->info('billing:renew finished', $stats);

        BillingJobHeartbeat::finish('billing:renew', $stats['failed'] > 0 ? 'failed' : 'ok', $stats);

        return self::SUCCESS;
    }

    private function handleAsync(): int
    {
        $this->info('Dispatching renewal jobs to queue...');

        $dispatched = 0;

        Subscription::whereIn('status', ['active', 'trialing'])
            ->where('is_current', 1)
            ->where('current_period_end', '<=', now())
            ->where(fn ($q) => $q->where('cancel_at_period_end', false)->orWhereNull('cancel_at_period_end'))
            ->select('id')
            ->chunkById(50, function ($batch) use (&$dispatched) {
                \App\Jobs\Billing\RenewSubscriptionBatchJob::dispatch($batch->pluck('id'));
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} batch job(s) to 'billing' queue.");

        BillingJobHeartbeat::finish('billing:renew', 'dispatched', ['batches' => $dispatched]);

        return self::SUCCESS;
    }

    /**
     * Renew a single subscription.
     *
     * @return string 'renewed' | 'invoiced' | 'skipped'
     */
    private function renewSubscription(Subscription $subscription): string
    {
        $company = $subscription->company;
        $plan = Plan::where('key', $subscription->plan_key)->first();

        if (! $plan) {
            $this->warn("  Skipped: subscription #{$subscription->id} — plan '{$subscription->plan_key}' not found.");

            return 'skipped';
        }

        // Determine renewal price and period
        $interval = $subscription->interval ?? 'monthly';
        $price = $interval === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $standardPeriodDays = $interval === 'yearly' ? 365 : 30;

        // Compute new period (start from previous period_end, not from now)
        $newPeriodStart = $subscription->current_period_end;
        $anchorDay = $subscription->billing_anchor_day;

        if ($anchorDay && $interval === 'monthly') {
            // Align period end to the anchor day of the appropriate month
            $candidate = $newPeriodStart->copy();

            if ($anchorDay > $candidate->day) {
                // Anchor day is later this month
                $candidate = $candidate->copy()->day(min($anchorDay, $candidate->daysInMonth));
            } else {
                // Anchor day is next month
                $candidate = $candidate->copy()->addMonthNoOverflow();
                $candidate = $candidate->day(min($anchorDay, $candidate->daysInMonth));
            }

            $newPeriodEnd = $candidate;
            $periodDays = $newPeriodStart->diffInDays($newPeriodEnd);

            // Prorate price for transition periods (non-standard length)
            if ($periodDays !== $standardPeriodDays && $price > 0) {
                $price = (int) floor($price * $periodDays / $standardPeriodDays);
            }
        } else {
            $newPeriodEnd = $interval === 'yearly'
                ? $newPeriodStart->copy()->addYear()
                : $newPeriodStart->copy()->addMonth();
        }

        // Free plans: extend period directly, no invoice needed
        if ($price <= 0) {
            $this->extendPeriod($subscription, $newPeriodStart, $newPeriodEnd);
            $this->line("  Renewed (free): company #{$company->id} — {$subscription->plan_key}");

            return 'renewed';
        }

        // Phase 1: Create renewal invoice (inside transaction)
        $invoice = DB::transaction(function () use ($company, $subscription, $plan, $price, $newPeriodStart, $newPeriodEnd) {
            // Idempotency: check if renewal invoice already exists for this period
            $existing = Invoice::where('subscription_id', $subscription->id)
                ->whereDate('period_start', $newPeriodStart->toDateString())
                ->whereDate('period_end', $newPeriodEnd->toDateString())
                ->whereNotIn('status', ['void'])
                ->first();

            if ($existing) {
                return $existing;
            }

            $invoice = InvoiceIssuer::createDraft(
                $company,
                $subscription->id,
                $newPeriodStart->toDateString(),
                $newPeriodEnd->toDateString(),
            );

            InvoiceIssuer::addLine(
                $invoice,
                'plan',
                "{$plan->name} plan renewal",
                $price,
                1,
            );

            // ADR-224: Include active addon lines in renewal invoice
            $activeAddons = CompanyAddonSubscription::where('company_id', $company->id)
                ->active()
                ->get();

            if ($activeAddons->isNotEmpty()) {
                $moduleNames = PlatformModule::whereIn('key', $activeAddons->pluck('module_key'))
                    ->pluck('name', 'key');

                foreach ($activeAddons as $addon) {
                    $moduleName = $moduleNames[$addon->module_key] ?? $addon->module_key;

                    InvoiceIssuer::addLine(
                        $invoice,
                        'addon',
                        "Addon: {$moduleName}",
                        $addon->amount_cents,
                        1,
                        moduleKey: $addon->module_key,
                    );
                }
            }

            // ADR-320: Apply coupon discount if subscription has an active coupon
            if ($subscription->coupon_id) {
                $coupon = BillingCoupon::find($subscription->coupon_id);

                if ($coupon && $coupon->isUsable()) {
                    InvoiceIssuer::applyCoupon($invoice, $coupon, $company);

                    // Decrement months remaining (null = unlimited)
                    if ($subscription->coupon_months_remaining !== null) {
                        $remaining = $subscription->coupon_months_remaining - 1;
                        $subscription->update([
                            'coupon_months_remaining' => $remaining,
                            'coupon_id' => $remaining <= 0 ? null : $subscription->coupon_id,
                        ]);
                    }
                }
                else {
                    // Coupon expired or exhausted — detach
                    $subscription->update(['coupon_id' => null, 'coupon_months_remaining' => null]);
                }
            }

            return InvoiceIssuer::finalize($invoice);
        });

        // If invoice was already paid (wallet credit covered it), extend period
        if ($invoice->status === 'paid') {
            $this->extendPeriod($subscription, $newPeriodStart, $newPeriodEnd);
            $this->line("  Renewed (wallet): company #{$company->id} — {$subscription->plan_key}");

            return 'renewed';
        }

        // ADR-328 S2: Check if debit was scheduled (SEPA preferred_debit_day)
        $scheduledDebit = \App\Core\Billing\ScheduledDebit::where('invoice_id', $invoice->id)
            ->pending()
            ->first();

        if ($scheduledDebit) {
            $this->extendPeriod($subscription, $newPeriodStart, $newPeriodEnd);
            $this->line("  Scheduled SEPA debit: company #{$company->id} — debit on {$scheduledDebit->debit_date->toDateString()}");

            return 'invoiced';
        }

        // Phase 2: Attempt provider payment (outside transaction — no DB lock during API)
        if ($subscription->provider && $subscription->provider !== 'internal') {
            $adapter = $this->resolveAdapter($subscription->provider);

            if ($adapter) {
                try {
                    $result = $adapter->collectInvoice($invoice, $company, [
                        'renewal' => 'true',
                        'subscription_id' => (string) $subscription->id,
                    ]);

                    if ($result['status'] === 'succeeded') {
                        // Record payment
                        DB::transaction(function () use ($invoice, $company, $subscription, $result) {
                            Payment::updateOrCreate(
                                ['provider_payment_id' => $result['provider_payment_id']],
                                [
                                    'company_id' => $company->id,
                                    'subscription_id' => $subscription->id,
                                    'invoice_id' => $invoice->id,
                                    'amount' => $result['amount'],
                                    'currency' => $invoice->currency ?? 'EUR',
                                    'status' => 'succeeded',
                                    'provider' => $subscription->provider,
                                ],
                            );

                            $invoice->update([
                                'status' => 'paid',
                                'paid_at' => now(),
                            ]);
                        });

                        // Extend period
                        $this->extendPeriod($subscription, $newPeriodStart, $newPeriodEnd);
                        $this->line("  Renewed (stripe): company #{$company->id} — {$subscription->plan_key}");

                        // ADR-272: Notify payment received
                        try {
                            $owner = $company->owner();

                            if ($owner) {
                                $owner->notify(new PaymentReceived($invoice->fresh()));
                            }
                        } catch (\Throwable $e) {
                            Log::warning('[billing:renew] Failed to send payment notification', [
                                'invoice_id' => $invoice->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        return 'renewed';
                    }
                } catch (\Throwable $e) {
                    Log::warning('[billing:renew] Provider payment failed', [
                        'subscription_id' => $subscription->id,
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Payment failed or no provider — invoice stays open, dunning will handle retries
        $this->line("  Invoiced: company #{$company->id} — invoice #{$invoice->id} awaiting payment");

        return 'invoiced';
    }

    /**
     * Extend subscription period after successful payment.
     */
    private function extendPeriod(Subscription $subscription, $newStart, $newEnd): void
    {
        $wasTrialing = $subscription->status === 'trialing';

        DB::transaction(function () use ($subscription, $newStart, $newEnd) {
            $sub = Subscription::where('id', $subscription->id)->lockForUpdate()->first();

            // Convert trialing → active on first renewal
            $status = $sub->status === 'trialing' ? 'active' : $sub->status;

            $sub->update([
                'status' => $status,
                'current_period_start' => $newStart,
                'current_period_end' => $newEnd,
                'trial_ends_at' => null,
            ]);
        });

        // ADR-286: Notify owner that trial has converted to active
        if ($wasTrialing) {
            $company = $subscription->company;
            $owner = $company?->owner();
            $owner?->notify(new \App\Notifications\Billing\TrialConverted($subscription));
        }
    }

    /**
     * Cancel subscriptions that are marked cancel_at_period_end and have reached their period end.
     */
    private function processCancellations(bool $dryRun, array &$stats): void
    {
        $toCancel = Subscription::whereIn('status', ['active', 'trialing'])
            ->where('is_current', 1)
            ->where('cancel_at_period_end', true)
            ->where('current_period_end', '<=', now())
            ->with('company')
            ->get();

        if ($toCancel->isEmpty()) {
            return;
        }

        $this->info("Found {$toCancel->count()} subscription(s) marked for cancellation at period end.");

        foreach ($toCancel as $subscription) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Cancel: company #{$subscription->company_id} — plan={$subscription->plan_key}");
                $stats['cancelled']++;

                continue;
            }

            try {
                DB::transaction(function () use ($subscription) {
                    $sub = Subscription::where('id', $subscription->id)->lockForUpdate()->first();

                    if (! $sub || $sub->status === 'cancelled') {
                        return;
                    }

                    $sub->update([
                        'status' => 'cancelled',
                        'is_current' => null,
                        'cancel_at_period_end' => false,
                    ]);

                    // Deactivate active addons
                    CompanyAddonSubscription::where('company_id', $sub->company_id)
                        ->active()
                        ->update(['deactivated_at' => now()]);
                });

                $stats['cancelled']++;
                $this->line("  Cancelled: company #{$subscription->company_id} — {$subscription->plan_key}");

                Log::channel('billing')->info('[billing:renew] Subscription cancelled at period end', [
                    'subscription_id' => $subscription->id,
                    'company_id' => $subscription->company_id,
                    'plan_key' => $subscription->plan_key,
                ]);
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::channel('billing')->error('[billing:renew] Failed to cancel subscription at period end', [
                    'subscription_id' => $subscription->id,
                    'company_id' => $subscription->company_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Failed to cancel: subscription #{$subscription->id} — {$e->getMessage()}");
            }
        }
    }

    private function resolveAdapter(string $provider): ?PaymentProviderAdapter
    {
        return match ($provider) {
            'stripe' => app(StripePaymentAdapter::class),
            default => null,
        };
    }
}
