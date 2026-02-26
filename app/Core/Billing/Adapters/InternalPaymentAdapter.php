<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;

/**
 * Internal payment adapter — no external payment processing.
 * Creates subscriptions in 'pending' state for admin approval.
 */
class InternalPaymentAdapter implements PaymentProviderAdapter
{
    public function key(): string
    {
        return 'internal';
    }

    public function availableMethods(): array
    {
        return ['manual'];
    }

    public function healthCheck(): HealthResult
    {
        return new HealthResult('healthy');
    }

    public function createCheckout(Company $company, string $planKey): CheckoutResult
    {
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
            'provider' => 'internal',
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

    public function handleWebhookEvent(array $payload, array $headers): WebhookHandlingResult
    {
        return new WebhookHandlingResult(handled: false);
    }
}
