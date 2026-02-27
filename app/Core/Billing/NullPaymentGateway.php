<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\PaymentGatewayProvider;
use App\Core\Models\Company;
use App\Core\Plans\Plan;

/**
 * Default payment gateway — no external payment processing.
 * Creates subscriptions in 'pending' state for admin approval.
 * Guards against duplicate pending subscriptions.
 */
class NullPaymentGateway implements PaymentGatewayProvider
{
    public function createCheckout(Company $company, string $planKey): CheckoutResult
    {
        // Guard: reject if company already has a pending subscription
        $existingPending = Subscription::where('company_id', $company->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            return new CheckoutResult(
                mode: 'internal',
                message: 'You already have a pending upgrade request. Please wait for administrator approval.',
            );
        }

        $plan = Plan::where('key', $planKey)->first();
        $trialDays = $plan?->trial_days ?? 0;

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => $planKey,
            'status' => $trialDays > 0 ? 'trialing' : 'pending',
            'provider' => 'null',
            'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'current_period_start' => $trialDays > 0 ? now() : null,
            'current_period_end' => $trialDays > 0 ? now()->addDays($trialDays) : null,
        ]);

        return new CheckoutResult(
            mode: 'internal',
            message: 'Your upgrade request has been submitted. An administrator will review it shortly.',
            subscriptionId: $subscription->id,
        );
    }

    public function handleCallback(array $payload, string $signature): ?array
    {
        return null;
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $subscription->update(['status' => 'cancelled']);
    }

    public function key(): string
    {
        return 'null';
    }
}
