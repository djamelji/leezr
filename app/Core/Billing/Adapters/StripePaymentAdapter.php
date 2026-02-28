<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\CheckoutResult;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Invoice;
use App\Core\Billing\Stripe\StripeEventProcessor;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stripe payment adapter — SDK live (ADR-137/138/139/140).
 *
 * Signature verification, refund, health check, webhook sync, collection, and reconciliation are live.
 * Checkout/callback/cancel remain stubs (future scope).
 *
 * Rate limiting: per-company isolation (ADR-140 D3d).
 */
class StripePaymentAdapter implements PaymentProviderAdapter
{
    private const RATE_LIMIT_MAX = 50;
    private const RATE_LIMIT_DECAY = 60;

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
        throw new \RuntimeException('Stripe checkout not implemented yet.');
    }

    public function handleCallback(array $payload, string $signature): ?array
    {
        throw new \RuntimeException('Stripe callback not implemented yet.');
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        throw new \RuntimeException('Stripe cancel not implemented yet.');
    }

    public function handleWebhookEvent(array $payload, array $headers): WebhookHandlingResult
    {
        return app(StripeEventProcessor::class)->process($payload);
    }

    public function collectInvoice(Invoice $invoice, Company $company, array $metadata = []): array
    {
        $this->enforceRateLimit($company->id);
        $this->setApiKey();

        $stripeCustomerId = CompanyPaymentCustomer::where('provider_key', 'stripe')
            ->where('company_id', $company->id)
            ->first()
            ?->provider_customer_id;

        if (! $stripeCustomerId) {
            return [
                'provider_payment_id' => null,
                'amount' => $invoice->amount_due,
                'status' => 'failed',
                'raw_response' => ['error' => 'No Stripe customer found.'],
            ];
        }

        try {
            $intent = $this->callStripeCreatePaymentIntent(
                $invoice->amount_due,
                strtolower($invoice->currency ?? 'eur'),
                $stripeCustomerId,
                array_merge([
                    'invoice_id' => (string) $invoice->id,
                    'company_id' => (string) $company->id,
                ], $metadata),
            );

            return [
                'provider_payment_id' => $intent->id,
                'amount' => $intent->amount_received ?? $intent->amount,
                'status' => $intent->status === 'succeeded' ? 'succeeded' : 'failed',
                'raw_response' => $intent->toArray(),
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return [
                'provider_payment_id' => null,
                'amount' => $invoice->amount_due,
                'status' => 'failed',
                'raw_response' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function refund(string $providerPaymentId, int $amount, array $metadata = []): array
    {
        $companyId = (int) ($metadata['company_id'] ?? 0) ?: null;
        $this->enforceRateLimit($companyId);
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

    // ── Reconciliation ──────────────────────────────────

    /**
     * List PaymentIntents for a company from Stripe (last N days).
     * Rate-limited per company. Auto-paginates.
     *
     * @return array[] Normalized list of payment intents
     */
    public function listPaymentIntents(int $companyId, int $sinceTimestamp): array
    {
        $this->enforceRateLimit($companyId);
        $this->setApiKey();

        $stripeCustomerId = CompanyPaymentCustomer::where('provider_key', 'stripe')
            ->where('company_id', $companyId)
            ->first()
            ?->provider_customer_id;

        if (! $stripeCustomerId) {
            return [];
        }

        $intents = $this->callStripeListPaymentIntents($stripeCustomerId, $sinceTimestamp);

        return array_map(fn ($pi) => [
            'id' => $pi->id,
            'amount' => $pi->amount,
            'status' => $pi->status,
            'metadata' => $pi->metadata?->toArray() ?? [],
            'created' => $pi->created,
            'charges' => collect($pi->charges?->data ?? [])->map(fn ($c) => [
                'refunded' => $c->refunded,
                'amount_refunded' => $c->amount_refunded,
            ])->all(),
        ], $intents);
    }

    // ── Protected wrappers (testable) ────────────────────

    protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata): \Stripe\PaymentIntent
    {
        return \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customerId,
            'confirm' => true,
            'off_session' => true,
            'metadata' => $metadata,
        ]);
    }

    protected function callStripeRefund(string $paymentIntentId, int $amount, array $metadata): \Stripe\Refund
    {
        return \Stripe\Refund::create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amount,
            'metadata' => $metadata,
        ]);
    }

    protected function callStripeListPaymentIntents(string $customerId, int $sinceTimestamp): array
    {
        $all = [];
        $params = [
            'customer' => $customerId,
            'created' => ['gte' => $sinceTimestamp],
            'limit' => 100,
            'expand' => ['data.charges'],
        ];

        do {
            $response = \Stripe\PaymentIntent::all($params);
            $all = array_merge($all, $response->data);

            if ($response->has_more && count($response->data) > 0) {
                $params['starting_after'] = end($response->data)->id;
            }
        } while ($response->has_more);

        return $all;
    }

    // ── Private helpers ──────────────────────────────────

    private static function rateLimitKey(?int $companyId = null): string
    {
        return $companyId ? "stripe-api:{$companyId}" : 'stripe-api:global';
    }

    private function enforceRateLimit(?int $companyId = null): void
    {
        $key = static::rateLimitKey($companyId);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_MAX)) {
            throw new \RuntimeException('Stripe API rate limit exceeded.');
        }

        RateLimiter::hit($key, self::RATE_LIMIT_DECAY);
    }

    private function setApiKey(): void
    {
        \Stripe\Stripe::setApiKey(config('billing.stripe.secret'));
    }
}
