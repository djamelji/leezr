<?php

namespace App\Core\Billing;

/**
 * Immutable value object returned by PaymentGatewayProvider::createCheckout().
 *
 * Modes:
 * - 'internal': No external redirect; show message to user (null provider)
 * - 'redirect': Redirect user to external payment page (Stripe, etc.)
 * - 'embedded': Render embedded payment form (future)
 */
class CheckoutResult
{
    public function __construct(
        public readonly string $mode,
        public readonly ?string $message = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?int $subscriptionId = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'mode' => $this->mode,
            'message' => $this->message,
            'redirect_url' => $this->redirectUrl,
            'subscription_id' => $this->subscriptionId,
        ], fn ($v) => $v !== null);
    }
}
