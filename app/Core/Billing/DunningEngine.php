<?php

namespace App\Core\Billing;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
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
 *   - Wallet payment is full-coverage only (no partial)
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

        // Phase 2: Wallet fallback (existing transactional flow)
        return DB::transaction(function () use ($invoice, $policy) {
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            // Guard: only overdue invoices
            if ($invoice->status !== 'overdue') {
                return 'skipped';
            }

            $maxRetries = $policy->max_retry_attempts;
            $newRetryCount = $invoice->retry_count + 1;

            // Try wallet payment (full-coverage only)
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

            return 'retried';
        });
    }

    /**
     * Attempt provider-first payment (Stripe charge, SEPA, etc.).
     *
     * Runs OUTSIDE DB::transaction to avoid holding row locks during API calls.
     * Returns 'provider_attempted' if provider accepted the charge (webhook will finalize).
     * Returns null if no provider or provider failed → caller falls back to wallet.
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

        try {
            $result = $adapter->collectInvoice($invoice, $invoice->company);

            return $result['status'] === 'succeeded' ? 'provider_attempted' : null;
        } catch (\Throwable) {
            return null; // Provider failed → wallet fallback
        }
    }

    private static function resolveAdapter(string $provider): ?PaymentProviderAdapter
    {
        return match ($provider) {
            'stripe' => app(StripePaymentAdapter::class),
            default => null,
        };
    }

    /**
     * Attempt to pay the invoice using wallet balance.
     * Returns true if fully paid, false otherwise.
     *
     * Full-coverage only — partial not supported.
     * If wallet_balance < amount_due → payment fails entirely (no partial).
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
                $subscription->update(['status' => 'suspended']);
            }

            // Company → suspended (idempotent)
            if ($company->status !== 'suspended') {
                $company->update(['status' => 'suspended']);
            }
        } elseif ($action === 'cancel') {
            // Subscription → cancelled
            if ($subscription) {
                $subscription->update(['status' => 'cancelled']);
            }

            // Cancel all scheduled PlanChangeIntents for this company
            PlanChangeIntent::where('company_id', $company->id)
                ->scheduled()
                ->update(['status' => 'cancelled']);

            // Downgrade company to free tier
            $company->update([
                'plan_key' => 'starter',
                'status' => $company->status !== 'suspended' ? 'suspended' : $company->status,
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
     *   - Uncollectible invoices are terminal — reactivation requires admin intervention
     */
    private static function checkReactivation(Company $company, ?int $subscriptionId): void
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
