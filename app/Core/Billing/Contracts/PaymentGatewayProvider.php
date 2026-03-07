<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;

interface PaymentGatewayProvider
{
    /**
     * Initiate a checkout/upgrade flow for a company.
     * Creates a Subscription in 'pending' state.
     */
    public function createCheckout(Company $company, string $planKey, string $interval = 'monthly'): CheckoutResult;

    /**
     * Handle provider callback/webhook.
     * Returns subscription update info, or null if no action needed.
     */
    public function handleCallback(array $payload, string $signature): ?array;

    /**
     * Cancel an active subscription.
     */
    public function cancelSubscription(Subscription $subscription): void;

    /**
     * Get the provider's unique key identifier.
     */
    public function key(): string;
}
