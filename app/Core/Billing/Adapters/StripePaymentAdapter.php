<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;

/**
 * Stripe payment adapter — stub.
 * Actual Stripe SDK integration deferred to future ADR.
 */
class StripePaymentAdapter implements PaymentProviderAdapter
{
    public function key(): string
    {
        return 'stripe';
    }

    public function availableMethods(): array
    {
        return ['card', 'sepa_debit'];
    }

    public function healthCheck(): HealthResult
    {
        return new HealthResult('down', 'Stripe SDK not installed.');
    }

    public function createCheckout(Company $company, string $planKey): CheckoutResult
    {
        throw new \RuntimeException('Stripe integration pending. SDK not installed.');
    }

    public function handleCallback(array $payload, string $signature): ?array
    {
        throw new \RuntimeException('Stripe integration pending. SDK not installed.');
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        throw new \RuntimeException('Stripe integration pending. SDK not installed.');
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookHandlingResult
    {
        return new WebhookHandlingResult(handled: false, error: 'Stripe SDK not installed.');
    }
}
