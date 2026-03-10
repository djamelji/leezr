<?php

namespace App\Core\Billing;

/**
 * Immutable value object returned by PaymentGatewayProvider::createCheckout().
 *
 * Modes:
 * - 'internal': No external redirect; show message to user (null provider)
 * - 'embedded': Render embedded Stripe Elements form on-site (ADR-302)
 */
class CheckoutResult
{
    public function __construct(
        public readonly string $mode,
        public readonly ?string $message = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?int $subscriptionId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $publishableKey = null,
        public readonly ?string $trialChargeTiming = null,
        public readonly ?array $allowedPaymentMethods = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'mode' => $this->mode,
            'message' => $this->message,
            'redirect_url' => $this->redirectUrl,
            'subscription_id' => $this->subscriptionId,
            'client_secret' => $this->clientSecret,
            'publishable_key' => $this->publishableKey,
            'trial_charge_timing' => $this->trialChargeTiming,
            'allowed_payment_methods' => $this->allowedPaymentMethods,
        ], fn ($v) => $v !== null);
    }
}
