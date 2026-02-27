<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;

/**
 * Stripe payment adapter — SDK bootstrapped (ADR-137 D3a).
 *
 * Signature verification, refund, and health check are live.
 * Checkout/callback/cancel/webhook handlers remain stubs (D3b scope).
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
        $this->setApiKey();

        try {
            \Stripe\Balance::retrieve();

            return new HealthResult('healthy');
        } catch (\Throwable $e) {
            return new HealthResult('down', $e->getMessage());
        }
    }

    public function createCheckout(Company $company, string $planKey): CheckoutResult
    {
        throw new \RuntimeException('Stripe checkout not implemented yet (D3b scope).');
    }

    public function handleCallback(array $payload, string $signature): ?array
    {
        throw new \RuntimeException('Stripe callback not implemented yet (D3b scope).');
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        throw new \RuntimeException('Stripe cancel not implemented yet (D3b scope).');
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookHandlingResult
    {
        // No business event handlers yet (D3b scope).
        return new WebhookHandlingResult(handled: false);
    }

    public function refund(string $providerPaymentId, int $amount, array $metadata = []): array
    {
        $this->setApiKey();

        try {
            $refund = $this->callStripeRefund($providerPaymentId, $amount, $metadata);

            return [
                'provider_refund_id' => $refund->id,
                'amount' => $refund->amount,
                'status' => $refund->status,
                'raw_response' => $refund->toArray(),
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \RuntimeException("Stripe refund failed: {$e->getMessage()}");
        }
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $sigHeader = $headers['stripe-signature'][0]
            ?? $headers['Stripe-Signature'][0]
            ?? $headers['stripe-signature']
            ?? $headers['Stripe-Signature']
            ?? '';

        $secret = config('billing.stripe.webhook_secret');

        if (! $secret) {
            throw new \RuntimeException('Stripe webhook secret not configured.');
        }

        try {
            \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $secret, 300);
        } catch (\UnexpectedValueException $e) {
            throw new \RuntimeException('Invalid webhook payload.');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new \RuntimeException('Invalid webhook signature.');
        }
    }

    /**
     * Testable wrapper for Stripe Refund API call.
     */
    protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
    {
        return \Stripe\Refund::create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amount,
            'metadata' => $metadata,
        ]);
    }

    private function setApiKey(): void
    {
        \Stripe\Stripe::setApiKey(config('billing.stripe.secret'));
    }
}
