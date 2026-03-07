<?php

namespace App\Core\Billing;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-229: Shared activator for checkout sessions.
 *
 * Called from three paths (triple recovery):
 *   1. Webhook: checkout.session.completed
 *   2. UI polling: GET /billing/checkout/status
 *   3. Cron: billing:recover-checkouts
 *
 * Fully idempotent: if subscription is already active/trialing → noop.
 * Payment record uses updateOrCreate on provider_payment_id.
 */
class CheckoutSessionActivator
{
    /**
     * Activate a subscription from a Stripe checkout session payload.
     *
     * @param  array  $session  Stripe Checkout Session object (or array representation)
     * @return ActivationResult
     */
    public static function activateFromStripeSession(array $session): ActivationResult
    {
        $metadata = $session['metadata'] ?? [];
        $subscriptionId = (int) ($metadata['subscription_id'] ?? 0);
        $companyId = (int) ($metadata['company_id'] ?? 0);
        $planKey = $metadata['plan_key'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;

        if (! $subscriptionId || ! $companyId || ! $planKey) {
            return new ActivationResult(activated: false, reason: 'Missing metadata.');
        }

        $company = Company::find($companyId);
        if (! $company) {
            return new ActivationResult(activated: false, reason: 'Company not found.');
        }

        $subscription = Subscription::find($subscriptionId);
        if (! $subscription) {
            return new ActivationResult(activated: false, reason: 'Subscription not found.');
        }

        // Idempotency: already activated
        if (in_array($subscription->status, ['active', 'trialing'])) {
            // Mark local checkout session as completed if exists
            static::markCheckoutSessionCompleted($session);

            Log::channel('billing')->info('Checkout activation noop (already activated)', [
                'subscription_id' => $subscriptionId,
                'company_id' => $companyId,
            ]);

            return new ActivationResult(activated: true, reason: 'already_activated', idempotent: true);
        }

        if ($subscription->status !== 'pending_payment') {
            return new ActivationResult(activated: false, reason: "Unexpected status: {$subscription->status}");
        }

        $amountTotal = $session['amount_total'] ?? 0;
        $currency = strtoupper($session['currency'] ?? config('billing.default_currency', 'EUR'));
        $plan = Plan::where('key', $planKey)->first();
        $trialDays = $plan?->trial_days ?? 0;

        DB::transaction(function () use ($subscription, $company, $planKey, $paymentIntentId, $amountTotal, $currency, $trialDays) {
            $subscription = Subscription::where('id', $subscription->id)->lockForUpdate()->first();
            if ($subscription->status !== 'pending_payment') {
                return; // Already activated by concurrent process
            }

            // Deactivate any previous current subscription
            Subscription::where('company_id', $company->id)
                ->where('is_current', 1)
                ->where('id', '!=', $subscription->id)
                ->update(['is_current' => null]);

            $periodEnd = $subscription->interval === 'yearly' ? now()->addYear() : now()->addMonth();
            $status = ($trialDays > 0) ? 'trialing' : 'active';

            $subscription->update([
                'status' => $status,
                'is_current' => 1,
                'current_period_start' => now(),
                'current_period_end' => $periodEnd,
                'trial_ends_at' => ($trialDays > 0) ? now()->addDays($trialDays) : null,
            ]);

            // Create invoice
            $invoice = InvoiceIssuer::createDraft(
                $company,
                $subscription->id,
                now()->toDateString(),
                $periodEnd->toDateString(),
            );
            InvoiceIssuer::addLine($invoice, 'plan', "{$planKey} plan", $amountTotal, 1);
            $invoice = InvoiceIssuer::finalize($invoice);

            // Create payment record (idempotent via updateOrCreate)
            if ($paymentIntentId) {
                Payment::updateOrCreate(
                    ['provider_payment_id' => $paymentIntentId],
                    [
                        'company_id' => $company->id,
                        'subscription_id' => $subscription->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $amountTotal,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'provider' => 'stripe',
                    ],
                );
            }

            // Sync company plan_key
            $company->update(['plan_key' => $planKey]);
        });

        // Mark local checkout session as completed
        static::markCheckoutSessionCompleted($session);

        Log::channel('billing')->info('Checkout session activated', [
            'subscription_id' => $subscriptionId,
            'company_id' => $companyId,
            'plan_key' => $planKey,
        ]);

        return new ActivationResult(activated: true, reason: 'checkout_activated');
    }

    /**
     * Mark the local BillingCheckoutSession as completed.
     */
    private static function markCheckoutSessionCompleted(array $session): void
    {
        $sessionId = $session['id'] ?? null;
        if (! $sessionId) {
            return;
        }

        BillingCheckoutSession::where('provider_session_id', $sessionId)
            ->where('status', 'created')
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
    }
}
