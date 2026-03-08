<?php

namespace App\Core\Billing;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Models\Company;
use App\Notifications\Billing\AccountSuspended;
use App\Notifications\Billing\PaymentFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Dunning engine — processes overdue invoices and enforces payment-failure policy.
 *
 * Pipeline:
 *   1. Scan finalized invoices with status=open and due_at < now - grace_period
 *   2. For each overdue invoice:
 *      a. Mark overdue, schedule next retry
 *      b. Set subscription to past_due (if currently active/trialing)
 *   3. Scan invoices with status=overdue and next_retry_at <= now → attempt retry
 *      a. Wallet-first payment (full-coverage only — partial not supported in LOT3)
 *      b. Paid → check reactivation (subscription + company)
 *      c. Failed + retries remain → reschedule
 *      d. Failed + max retries → uncollectible + failure action
 *
 * Policy matrix (from PlatformBillingPolicy):
 *   - grace_period_days: days after due_at before first dunning action
 *   - max_retry_attempts: max retries before giving up
 *   - retry_intervals_days: [1, 3, 7] — days between retries
 *   - failure_action: 'suspend' | 'cancel' — what happens when max retries exhausted
 *
 * Subscription status transitions:
 *   - active/trialing → past_due (first invoice goes overdue)
 *   - past_due → active (all overdue invoices paid — bounded reactivation)
 *   - past_due → suspended (failure_action=suspend, max retries exhausted)
 *   - past_due → cancelled (failure_action=cancel, max retries exhausted)
 *
 * Invariants:
 *   - Only finalized invoices are processed (draft/void excluded)
 *   - Each invoice processed at most once per run (idempotent scan)
 *   - Company suspension/cancellation is idempotent
 *   - retry_count increments exactly once per retry attempt
 *   - Voided invoices are never processed
 *   - Wallet supports split payment: partial wallet + provider remainder (ADR-265)
 */
class DunningEngine
{
    /**
     * Process all overdue invoices.
     * Called by billing:process-dunning command.
     *
     * @return array{processed: int, retried: int, exhausted: int, skipped: int}
     */
    public static function processOverdueInvoices(): array
    {
        $policy = PlatformBillingPolicy::instance();
        $stats = ['processed' => 0, 'retried' => 0, 'exhausted' => 0, 'skipped' => 0];

        // Phase 1: Mark newly overdue invoices (open + past grace period)
        $newlyOverdue = Invoice::where('status', 'open')
            ->whereNotNull('finalized_at')
            ->whereNull('voided_at')
            ->where('amount_due', '>', 0)
            ->where('due_at', '<=', now()->subDays($policy->grace_period_days))
            ->get();

        foreach ($newlyOverdue as $invoice) {
            static::markOverdue($invoice, $policy);
            $stats['processed']++;
        }

        // Phase 2: Retry overdue invoices that are due for retry
        $dueForRetry = Invoice::where('status', 'overdue')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->get();

        foreach ($dueForRetry as $invoice) {
            $result = static::attemptRetry($invoice, $policy);

            if ($result === 'retried') {
                $stats['retried']++;
            } elseif ($result === 'exhausted') {
                $stats['exhausted']++;
            } else {
                $stats['skipped']++;
            }

            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Admin-initiated single invoice retry.
     *
     * Wraps the internal attemptRetry() logic for a single invoice.
     * Disambiguates the 'retried' return value by checking post-state.
     *
     * @return string 'paid' | 'retried' | 'exhausted' | 'skipped' | 'provider_attempted'
     */
    public static function retrySingleInvoice(Invoice $invoice): string
    {
        $policy = PlatformBillingPolicy::instance();
        $result = static::attemptRetry($invoice, $policy);

        if ($result === 'provider_attempted') {
            return 'provider_attempted';
        }

        // attemptRetry returns 'retried' for both "paid" and "rescheduled".
        // Disambiguate by checking the invoice's current status.
        if ($result === 'retried') {
            $invoice->refresh();

            return $invoice->status === 'paid' ? 'paid' : 'retried';
        }

        return $result;
    }

    /**
     * Mark an open invoice as overdue and schedule first retry.
     * Also transitions the subscription to past_due.
     */
    private static function markOverdue(Invoice $invoice, PlatformBillingPolicy $policy): void
    {
        DB::transaction(function () use ($invoice, $policy) {
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            // Guard: only open invoices
            if ($invoice->status !== 'open') {
                return;
            }

            $retryIntervals = $policy->retry_intervals_days ?? [1, 3, 7];
            $nextRetryDays = $retryIntervals[0] ?? 1;

            $invoice->update([
                'status' => 'overdue',
                'next_retry_at' => now()->addDays($nextRetryDays),
            ]);

            // Transition subscription to past_due
            if ($invoice->subscription_id) {
                Subscription::where('id', $invoice->subscription_id)
                    ->whereIn('status', ['active', 'trialing'])
                    ->update(['status' => 'past_due']);
            }
        });
    }

    /**
     * Attempt a retry on an overdue invoice.
     *
     * @return string 'retried' | 'exhausted' | 'skipped' | 'provider_attempted'
     */
    private static function attemptRetry(Invoice $invoice, PlatformBillingPolicy $policy): string
    {
        Log::channel('billing')->info('Dunning retry attempt', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'amount_due' => $invoice->amount_due,
            'retry_count' => $invoice->retry_count,
        ]);

        // Phase 1: Provider-first collection (OUTSIDE transaction — no lock during API call)
        $providerResult = static::attemptProviderPayment($invoice);

        if ($providerResult === 'provider_attempted') {
            // Provider accepted the charge — webhook will finalize state.
            // Increment retry_count inside a short transaction.
            DB::transaction(function () use ($invoice) {
                $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

                if ($invoice->status !== 'overdue') {
                    return;
                }

                $invoice->update(['retry_count' => $invoice->retry_count + 1]);
            });

            return 'provider_attempted';
        }

        // Phase 2: Split payment — wallet partial + provider remainder (ADR-265)
        // Runs OUTSIDE transaction to avoid holding locks during Stripe API call
        $splitResult = static::attemptSplitPayment($invoice);

        // Phase 3: Wallet fallback + finalization (inside transaction)
        return DB::transaction(function () use ($invoice, $policy, $splitResult) {
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            // Guard: only overdue invoices
            if ($invoice->status !== 'overdue') {
                return 'skipped';
            }

            $maxRetries = $policy->max_retry_attempts;
            $newRetryCount = $invoice->retry_count + 1;

            // Try wallet payment (full-coverage)
            $paid = static::attemptWalletPayment($invoice);

            if ($paid) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'retry_count' => $newRetryCount,
                    'next_retry_at' => null,
                ]);

                // Bounded reactivation: restore subscription + company if no outstanding invoices
                static::checkReactivation($invoice->company, $invoice->subscription_id);

                return 'retried';
            }

            // ADR-265: Split payment succeeded — wallet partial + provider remainder
            if ($splitResult !== null) {
                $splitPaid = static::finalizeSplitPayment($invoice, $splitResult, $newRetryCount);

                if ($splitPaid) {
                    return 'retried';
                }
            }

            // Payment failed — check if max retries exhausted
            if ($newRetryCount >= $maxRetries) {
                $invoice->update([
                    'status' => 'uncollectible',
                    'retry_count' => $newRetryCount,
                    'next_retry_at' => null,
                ]);

                // Ledger: record write-off (ADR-142 D3f)
                try {
                    LedgerService::recordWriteOff($invoice);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[ledger] dunning writeoff recording failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Apply failure action to the company
                static::applyFailureAction($invoice->company, $policy);

                return 'exhausted';
            }

            // Schedule next retry
            $retryIntervals = $policy->retry_intervals_days ?? [1, 3, 7];
            $nextRetryDays = $retryIntervals[$newRetryCount] ?? end($retryIntervals);

            $invoice->update([
                'retry_count' => $newRetryCount,
                'next_retry_at' => now()->addDays($nextRetryDays),
            ]);

            // ADR-226: Notify company owner of payment failure
            static::notifyPaymentFailed($invoice);

            return 'retried';
        });
    }

    /**
     * Attempt provider-first payment with fallback across saved payment methods.
     *
     * Runs OUTSIDE DB::transaction to avoid holding row locks during API calls.
     * Priority: default method first, then others ordered by id.
     * Returns 'provider_attempted' if any method accepted the charge.
     * Returns null if all methods fail → caller falls back to wallet.
     */
    private static function attemptProviderPayment(Invoice $invoice): ?string
    {
        if ($invoice->amount_due <= 0) {
            return null;
        }

        $subscription = $invoice->subscription;

        if (! $subscription || ! $subscription->provider || $subscription->provider === 'internal') {
            return null;
        }

        $adapter = static::resolveAdapter($subscription->provider);

        if (! $adapter) {
            return null;
        }

        // Resolve all saved payment methods for fallback
        $methods = PaymentMethodResolver::resolveForCompany($invoice->company);

        if ($methods->isEmpty()) {
            // No saved methods — try default customer charge
            try {
                $result = $adapter->collectInvoice($invoice, $invoice->company);

                return $result['status'] === 'succeeded' ? 'provider_attempted' : null;
            } catch (\Throwable) {
                return null;
            }
        }

        // Try each payment method in order (default first)
        foreach ($methods as $method) {
            if (! $method->provider_payment_method_id) {
                continue;
            }

            Log::channel('billing')->info('[billing] fallback payment method attempt', [
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'payment_method_id' => $method->provider_payment_method_id,
                'method_key' => $method->method_key,
                'is_default' => $method->is_default,
            ]);

            try {
                $result = $adapter->chargeInvoiceWithPaymentMethod(
                    $invoice,
                    $method->provider_payment_method_id,
                );

                if ($result['status'] === 'succeeded') {
                    return 'provider_attempted';
                }

                Log::channel('billing')->info('[billing] payment attempt failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'method_key' => $method->method_key,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            } catch (\Throwable $e) {
                Log::channel('billing')->info('[billing] payment attempt failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'method_key' => $method->method_key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null; // All methods failed → wallet fallback
    }

    private static function resolveAdapter(string $provider): ?PaymentProviderAdapter
    {
        return match ($provider) {
            'stripe' => app(StripePaymentAdapter::class),
            default => null,
        };
    }

    /**
     * Attempt to pay the invoice using wallet balance (full-coverage).
     * Returns true if fully paid, false otherwise.
     */
    private static function attemptWalletPayment(Invoice $invoice): bool
    {
        $company = $invoice->company;
        $amountDue = $invoice->amount_due;

        if ($amountDue <= 0) {
            return true;
        }

        $walletBalance = WalletLedger::balance($company);

        if ($walletBalance >= $amountDue) {
            WalletLedger::debit(
                company: $company,
                amount: $amountDue,
                sourceType: 'dunning_payment',
                sourceId: $invoice->id,
                description: "Dunning retry payment for invoice {$invoice->number}",
                actorType: 'system',
                idempotencyKey: "dunning-retry-{$invoice->id}-{$invoice->retry_count}",
            );

            return true;
        }

        return false;
    }

    /**
     * ADR-265: Attempt split payment — wallet partial + provider remainder.
     *
     * Runs OUTSIDE transaction to avoid holding row locks during Stripe API calls.
     * Only attempted when wallet has partial balance (0 < balance < amount_due).
     *
     * @return array{wallet_amount: int, provider_payment_id: string, provider_amount: int}|null
     */
    private static function attemptSplitPayment(Invoice $invoice): ?array
    {
        $company = $invoice->company;
        $amountDue = $invoice->amount_due;

        if ($amountDue <= 0) {
            return null;
        }

        $walletBalance = WalletLedger::balance($company);

        // No partial balance, or wallet covers full amount (handled by attemptWalletPayment)
        if ($walletBalance <= 0 || $walletBalance >= $amountDue) {
            return null;
        }

        $subscription = $invoice->subscription;

        if (! $subscription || ! $subscription->provider || $subscription->provider === 'internal') {
            return null;
        }

        $adapter = static::resolveAdapter($subscription->provider);

        if (! $adapter) {
            return null;
        }

        $remainder = $amountDue - $walletBalance;

        // Try each payment method for the remainder amount
        $methods = PaymentMethodResolver::resolveForCompany($company);

        foreach ($methods as $method) {
            if (! $method->provider_payment_method_id) {
                continue;
            }

            Log::channel('billing')->info('[billing] split payment attempt', [
                'invoice_id' => $invoice->id,
                'company_id' => $company->id,
                'wallet_amount' => $walletBalance,
                'provider_remainder' => $remainder,
                'payment_method_id' => $method->provider_payment_method_id,
            ]);

            try {
                $result = $adapter->chargeInvoiceWithPaymentMethod(
                    $invoice,
                    $method->provider_payment_method_id,
                    $remainder,
                );

                if ($result['status'] === 'succeeded') {
                    return [
                        'wallet_amount' => $walletBalance,
                        'provider_payment_id' => $result['provider_payment_id'],
                        'provider_amount' => $remainder,
                    ];
                }

                Log::channel('billing')->info('[billing] split payment provider failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            } catch (\Throwable $e) {
                Log::channel('billing')->info('[billing] split payment provider failed', [
                    'invoice_id' => $invoice->id,
                    'payment_method_id' => $method->provider_payment_method_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null; // All methods failed
    }

    /**
     * ADR-265: Finalize a split payment — debit wallet + record provider payment + mark paid.
     *
     * Called INSIDE transaction with invoice locked.
     * Returns true if split payment finalized, false if wallet debit failed.
     */
    private static function finalizeSplitPayment(Invoice $invoice, array $splitResult, int $newRetryCount): bool
    {
        // Record provider payment first (it already happened — must not lose it)
        Payment::updateOrCreate(
            ['provider_payment_id' => $splitResult['provider_payment_id']],
            [
                'company_id' => $invoice->company_id,
                'subscription_id' => $invoice->subscription_id,
                'invoice_id' => $invoice->id,
                'amount' => $splitResult['provider_amount'],
                'currency' => $invoice->currency ?? 'EUR',
                'status' => 'succeeded',
                'provider' => $invoice->subscription?->provider ?? 'stripe',
            ],
        );

        // Debit wallet for the partial amount
        try {
            WalletLedger::debit(
                company: $invoice->company,
                amount: $splitResult['wallet_amount'],
                sourceType: 'dunning_split_payment',
                sourceId: $invoice->id,
                description: "Split payment (wallet portion) for invoice {$invoice->number}",
                actorType: 'system',
                idempotencyKey: "dunning-split-{$invoice->id}-{$invoice->retry_count}",
            );
        } catch (\Throwable $e) {
            Log::channel('billing')->error('[billing] Split payment wallet debit failed after provider charged', [
                'invoice_id' => $invoice->id,
                'wallet_amount' => $splitResult['wallet_amount'],
                'provider_amount' => $splitResult['provider_amount'],
                'provider_payment_id' => $splitResult['provider_payment_id'],
                'error' => $e->getMessage(),
            ]);

            // Provider payment is recorded — invoice stays overdue for admin review
            return false;
        }

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'retry_count' => $newRetryCount,
            'next_retry_at' => null,
        ]);

        static::checkReactivation($invoice->company, $invoice->subscription_id);

        Log::channel('billing')->info('[billing] Split payment succeeded', [
            'invoice_id' => $invoice->id,
            'wallet_amount' => $splitResult['wallet_amount'],
            'provider_amount' => $splitResult['provider_amount'],
        ]);

        return true;
    }

    /**
     * Apply the failure action to a company when max retries are exhausted.
     *
     * suspend: subscription → suspended, company → suspended
     * cancel: subscription → cancelled, scheduled intents → cancelled,
     *         company.plan_key → starter, company → suspended
     *
     * Idempotent: if company is already suspended, no-op.
     */
    public static function applyFailureAction(Company $company, PlatformBillingPolicy $policy): void
    {
        $action = $policy->failure_action;

        // Find the active/trialing/past_due subscription
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->first();

        if ($action === 'suspend') {
            // Subscription → suspended
            if ($subscription) {
                $subscription->update(['status' => 'suspended', 'is_current' => null]);
            }

            // Company → suspended (idempotent)
            if ($company->status !== 'suspended') {
                $company->update(['status' => 'suspended']);

                // ADR-226: Notify company owner of suspension
                static::notifyAccountSuspended($company);
            }
        } elseif ($action === 'cancel') {
            // Subscription → cancelled
            if ($subscription) {
                $subscription->update(['status' => 'cancelled', 'is_current' => null]);
            }

            // Cancel all scheduled PlanChangeIntents for this company
            PlanChangeIntent::where('company_id', $company->id)
                ->scheduled()
                ->update(['status' => 'cancelled']);

            // Downgrade company to free tier
            $wasSuspended = $company->status === 'suspended';

            $company->update([
                'plan_key' => 'starter',
                'status' => $company->status !== 'suspended' ? 'suspended' : $company->status,
            ]);

            // ADR-226: Notify company owner of suspension (only if newly suspended)
            if (! $wasSuspended) {
                static::notifyAccountSuspended($company);
            }
        }
    }

    /**
     * ADR-226: Notify company owner of payment failure.
     */
    private static function notifyPaymentFailed(Invoice $invoice): void
    {
        try {
            $owner = $invoice->company?->owner();

            if ($owner) {
                $owner->notify(new PaymentFailed($invoice));
            }
        } catch (\Throwable $e) {
            Log::warning('[dunning] Failed to send PaymentFailed notification', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ADR-226: Notify company owner of account suspension.
     */
    private static function notifyAccountSuspended(Company $company): void
    {
        try {
            $owner = $company->owner();

            if ($owner) {
                $owner->notify(new AccountSuspended());
            }
        } catch (\Throwable $e) {
            Log::warning('[dunning] Failed to send AccountSuspended notification', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bounded reactivation: when an overdue invoice is paid, check if
     * the subscription and company can be reactivated.
     *
     * Rules:
     *   - Subscription reverts to active if no more overdue invoices for it
     *   - Company reverts to active if suspended and no more overdue/uncollectible invoices
     *   - Uncollectible invoices block reactivation until paid
     */
    public static function checkReactivation(Company $company, ?int $subscriptionId): void
    {
        // Reactivate subscription if no remaining overdue invoices
        if ($subscriptionId) {
            $hasSubscriptionOverdue = Invoice::where('subscription_id', $subscriptionId)
                ->where('status', 'overdue')
                ->exists();

            if (!$hasSubscriptionOverdue) {
                Subscription::where('id', $subscriptionId)
                    ->where('status', 'past_due')
                    ->update(['status' => 'active']);
            }
        }

        // Reactivate company if suspended and no outstanding invoices
        $company->refresh();

        if ($company->status === 'suspended') {
            $hasCompanyOutstanding = Invoice::where('company_id', $company->id)
                ->whereIn('status', ['overdue', 'uncollectible'])
                ->exists();

            if (!$hasCompanyOutstanding) {
                $company->update(['status' => 'active']);
            }
        }
    }
}
