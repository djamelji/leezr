<?php

namespace App\Core\Billing;

use App\Core\Billing\Dunning\DunningNotifier;
use App\Core\Billing\Dunning\DunningRetryStrategy;
use App\Core\Billing\Dunning\DunningTransitioner;
use App\Core\Models\Company;
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
    private static function attemptRetry(Invoice $invoice, PlatformBillingPolicy $policy): string
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
        $splitResult = DunningRetryStrategy::attemptSplitPayment($invoice);

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
            $paid = DunningRetryStrategy::attemptWalletPayment($invoice);

            if ($paid) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => now(),
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
