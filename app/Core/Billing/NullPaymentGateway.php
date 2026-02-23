<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\PaymentGatewayProvider;
use App\Core\Models\Company;

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

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => $planKey,
            'status' => 'pending',
            'provider' => 'null',
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
