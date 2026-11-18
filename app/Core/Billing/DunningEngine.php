<?php

namespace App\Core\Billing;

use App\Core\Billing\Dunning\DunningNotifier;
use App\Core\Billing\Dunning\DunningRetryStrategy;
use App\Core\Billing\Dunning\DunningTransitioner;
use App\Core\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dunning engine — processes overdue invoices and enforces payment-failure policy.
 *
 * Orchestrates three sub-services:
 *   - DunningRetryStrategy: payment attempts (provider, wallet, split)
 *   - DunningTransitioner: state transitions (overdue, suspend, reactivate)
 *   - DunningNotifier: notifications (payment failed, account suspended)
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
 *   - ADR-328 S6: Addon invoices (standalone or annexe) are dunned identically to
 *     subscription invoices. There is NO per-module deactivation on payment failure.
 *     The only escalation is company-level suspension via DunningTransitioner.
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
        Invoice::where('status', 'open')
            ->whereNotNull('finalized_at')
            ->whereNull('voided_at')
            ->where('amount_due', '>', 0)
            ->where('due_at', '<=', now()->subDays($policy->grace_period_days))
            ->with('company:id,name,slug,status,market_key')
            ->chunkById(100, function ($batch) use (&$stats, $policy) {
                foreach ($batch as $invoice) {
                    // ADR-325: SEPA first payment failure — immediate suspend if policy says so
                    if (static::isSepaFirstPaymentFailure($invoice, $policy)) {
                        static::handleSepaFirstFailure($invoice, $policy);
                        $stats['exhausted']++;
                        $stats['processed']++;
                        continue;
                    }

                    DunningTransitioner::markOverdue($invoice, $policy);
                    $stats['processed']++;
                }
            });

        // Phase 2: Retry overdue invoices that are due for retry
        Invoice::where('status', 'overdue')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->with(['company:id,name,slug,status,market_key', 'subscription:id,company_id,status,provider'])
            ->chunkById(50, function ($batch) use (&$stats, $policy) {
                foreach ($batch as $invoice) {
                    $result = static::retryIfDue($invoice, 'scheduled_scan', $policy);

                    if ($result === 'retried') {
                        $stats['retried']++;
                    } elseif ($result === 'exhausted') {
                        $stats['exhausted']++;
                    } else {
                        $stats['skipped']++;
                    }

                    $stats['processed']++;
                }
            });

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
        // ADR-830 : an admin retry ignores the schedule (explicit human action) but never runs
        // concurrently with another attempt on the same invoice.
        $result = static::withInvoiceClaim($invoice->id, 'admin', fn () => static::attemptRetry($invoice->fresh(), $policy, $invoice->fresh()->retry_count));

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
     * ADR-830 — the ONLY automatic path to a dunning attempt (scheduled scan and deferred job).
     *
     * An attempt belongs to ONE scheduled due date. It is claimed under an atomic per-invoice lock
     * (cache store of the environment), then re-read : it runs only if the invoice is still overdue and
     * its next_retry_at is reached. A late job, a redelivered job or a second concurrent worker finds
     * the lock held, or next_retry_at already pushed by the attempt that ran, and does nothing. The
     * retry_count read at claim time is re-checked when the attempt is recorded (row lock), so an
     * attempt can never be counted twice even if the cache lock expired meanwhile.
     *
     * A SQL lock does not make the external charge idempotent : the provider call keeps its own
     * idempotency key (Stripe : per invoice and payment method) ; the claim only guarantees that Leezr
     * starts and records at most one attempt per due date.
     *
     * @return string 'retried' | 'exhausted' | 'skipped' | 'provider_attempted' | 'not_due' | 'claimed_elsewhere'
     */
    public static function retryIfDue(Invoice $invoice, string $source = 'job', ?PlatformBillingPolicy $policy = null): string
    {
        $policy ??= PlatformBillingPolicy::instance();

        return static::withInvoiceClaim($invoice->id, $source, function () use ($invoice, $policy, $source) {
            $current = Invoice::withoutGlobalScopes()->with(['company:id,name,slug,status,market_key', 'subscription:id,company_id,status,provider'])->find($invoice->id);

            if (! $current || $current->status !== 'overdue' || $current->next_retry_at === null || $current->next_retry_at->gt(now())) {
                Log::channel('billing')->info('[dunning] attempt skipped — not due', [
                    'invoice_id' => $invoice->id, 'source' => $source,
                    'status' => $current?->status, 'next_retry_at' => $current?->next_retry_at?->toIso8601String(),
                ]);

                return 'not_due';
            }

            return static::attemptRetry($current, $policy, $current->retry_count);
        });
    }

    /** ADR-830 — run $attempt while holding the per-invoice claim ; 'claimed_elsewhere' when another worker holds it. */
    private static function withInvoiceClaim(int $invoiceId, string $source, \Closure $attempt): string
    {
        $lock = Cache::lock("billing:dunning:invoice:{$invoiceId}", 600);

        if (! $lock->get()) {
            Log::channel('billing')->info('[dunning] attempt skipped — claimed by another worker', ['invoice_id' => $invoiceId, 'source' => $source]);

            return 'claimed_elsewhere';
        }

        try {
            return $attempt();
        } finally {
            $lock->release();
        }
    }

    /**
     * Apply the failure action to a company when max retries are exhausted.
     * Delegates to DunningTransitioner.
     */
    public static function applyFailureAction(Company $company, PlatformBillingPolicy $policy): void
    {
        DunningTransitioner::applyFailureAction($company, $policy);
    }

    /**
     * Bounded reactivation after payment.
     * Delegates to DunningTransitioner.
     */
    public static function checkReactivation(Company $company, ?int $subscriptionId): void
    {
        DunningTransitioner::checkReactivation($company, $subscriptionId);
    }

    /**
     * Attempt a retry on an overdue invoice.
     *
     * @return string 'retried' | 'exhausted' | 'skipped' | 'provider_attempted'
     */
    private static function attemptRetry(Invoice $invoice, PlatformBillingPolicy $policy, ?int $claimedRetryCount = null): string
    {
        Log::channel('billing')->info('Dunning retry attempt', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'amount_due' => $invoice->amount_due,
            'retry_count' => $invoice->retry_count,
        ]);

        // Phase 1: Provider-first collection (OUTSIDE transaction — no lock during API call)
        $providerResult = DunningRetryStrategy::attemptProviderPayment($invoice);

        if ($providerResult === 'provider_attempted') {
            // Provider accepted the charge — webhook will finalize state.
            // Increment retry_count inside a short transaction.
            DB::transaction(function () use ($invoice, $policy, $claimedRetryCount) {
                $invoice = Invoice::withoutGlobalScopes()->where('id', $invoice->id)->lockForUpdate()->first();

                if ($invoice->status !== 'overdue' || ($claimedRetryCount !== null && $invoice->retry_count !== $claimedRetryCount)) {
                    return; // ADR-830 : paid meanwhile, or this attempt was already counted
                }

                // ADR-830 : the charge was accepted, the webhook finalizes. Until then the invoice waits for
                // its NEXT scheduled date (policy intervals, unchanged) instead of staying due — a delayed
                // webhook no longer triggers a new charge at every scan.
                $newRetryCount = $invoice->retry_count + 1;
                $intervals = $policy->retry_intervals_days ?? [1, 3, 7];
                $invoice->update([
                    'retry_count' => $newRetryCount,
                    'next_retry_at' => now()->addDays($intervals[$newRetryCount] ?? end($intervals)),
                ]);
            });

            return 'provider_attempted';
        }

        // Phase 2: Split payment — wallet partial + provider remainder (ADR-265)
        // Runs OUTSIDE transaction to avoid holding locks during Stripe API call
        $splitResult = DunningRetryStrategy::attemptSplitPayment($invoice);

        // Phase 3: Wallet fallback + finalization (inside transaction)
        return DB::transaction(function () use ($invoice, $policy, $splitResult, $claimedRetryCount) {
            $invoice = Invoice::withoutGlobalScopes()->where('id', $invoice->id)->lockForUpdate()->first();

            // Guard: only overdue invoices
            if ($invoice->status !== 'overdue') {
                return 'skipped';
            }

            // ADR-830 : the attempt claimed at retry_count N is recorded once ; a concurrent or redelivered
            // attempt that finds N already incremented records nothing (no artificial accumulation).
            if ($claimedRetryCount !== null && $invoice->retry_count !== $claimedRetryCount) {
                return 'skipped';
            }

            $maxRetries = $policy->max_retry_attempts;
            $newRetryCount = $invoice->retry_count + 1;

            // Try wallet payment (full-coverage)
            $paid = DunningRetryStrategy::attemptWalletPayment($invoice);

            if ($paid) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'amount_due' => 0,
                    'retry_count' => $newRetryCount,
                    'next_retry_at' => null,
                ]);

                // Bounded reactivation: restore subscription + company if no outstanding invoices
                DunningTransitioner::checkReactivation($invoice->company, $invoice->subscription_id);

                return 'retried';
            }

            // ADR-265: Split payment succeeded — wallet partial + provider remainder
            if ($splitResult !== null) {
                $splitPaid = DunningRetryStrategy::finalizeSplitPayment($invoice, $splitResult, $newRetryCount);

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
                    Log::warning('[ledger] dunning writeoff recording failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Apply failure action to the company
                DunningTransitioner::applyFailureAction($invoice->company, $policy);

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
            DunningNotifier::notifyPaymentFailed($invoice);

            return 'retried';
        });
    }

    /**
     * ADR-325: Check if this is a SEPA first payment failure that should be handled specially.
     */
    private static function isSepaFirstPaymentFailure(Invoice $invoice, PlatformBillingPolicy $policy): bool
    {
        if ($policy->sepa_first_failure_action !== 'suspend') {
            return false;
        }

        // Check if the company's default payment method is SEPA
        $defaultPm = CompanyPaymentProfile::where('company_id', $invoice->company_id)
            ->where('is_default', true)
            ->first();

        if (! $defaultPm || $defaultPm->method_key !== 'sepa_debit') {
            return false;
        }

        // Check if this is the first invoice for this subscription (first payment)
        $subscription = Subscription::find($invoice->subscription_id);
        if (! $subscription) {
            return false;
        }

        $previousPaidInvoices = Invoice::where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->where('id', '!=', $invoice->id)
            ->count();

        return $previousPaidInvoices === 0;
    }

    /**
     * ADR-325: Handle SEPA first payment failure — immediate suspend.
     */
    private static function handleSepaFirstFailure(Invoice $invoice, PlatformBillingPolicy $policy): void
    {
        Log::channel('billing')->warning('SEPA first payment failure — immediate suspend', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
        ]);

        $invoice->update([
            'status' => 'uncollectible',
            'retry_count' => 0,
            'next_retry_at' => null,
        ]);

        DunningTransitioner::applyFailureAction($invoice->company, $policy);
    }
}
