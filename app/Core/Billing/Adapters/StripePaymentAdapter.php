<?php

namespace App\Core\Billing\Adapters;

use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\BillingExpectedConfirmation;
use App\Core\Billing\CheckoutResult;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\Contracts\PaymentProviderAdapter;
use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Stripe\StripeEventProcessor;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stripe payment adapter — SDK live (ADR-137/138/139/140/222).
 *
 * Stripe is a payment rail only — no Stripe Subscriptions/Invoices.
 * Local billing engine is master; Stripe handles checkout + collection.
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

    public function createCheckout(Company $company, string $planKey, string $interval = 'monthly'): CheckoutResult
    {
        $this->setApiKey();

        $plan = Plan::where('key', $planKey)->firstOrFail();

        // Create subscription in pending_payment state (not yet current)
        $subscription = DB::transaction(function () use ($company, $planKey, $interval) {
            return Subscription::create([
                'company_id' => $company->id,
                'plan_key' => $planKey,
                'interval' => $interval,
                'status' => 'pending_payment',
                'provider' => 'stripe',
                'is_current' => null,
            ]);
        });

        // Ensure Stripe customer exists
        $stripeCustomer = $this->ensureStripeCustomer($company);

        // Use correct price based on interval
        $price = $interval === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        // ADR-235: Currency from company's market via wallet
        $currency = strtolower(WalletLedger::ensureWallet($company)->currency);

        // Create Checkout Session
        $session = $this->callStripeCreateCheckoutSession([
            'mode' => 'payment',
            'customer' => $stripeCustomer->provider_customer_id,
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => ['name' => "{$plan->name} Plan"],
                    'unit_amount' => $price,
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'company_id' => (string) $company->id,
                'subscription_id' => (string) $subscription->id,
                'plan_key' => $planKey,
            ],
            'success_url' => config('app.url') . '/company/billing?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.url') . '/company/plan',
        ], [
            'idempotency_key' => "checkout_{$subscription->id}_{$planKey}",
        ]);

        // ADR-228: Track expected webhook confirmation
        $sessionId = $session->id ?? null;
        if ($sessionId) {
            BillingExpectedConfirmation::create([
                'company_id' => $company->id,
                'provider_key' => 'stripe',
                'expected_event_type' => 'checkout.session.completed',
                'provider_reference' => $sessionId,
                'expected_by' => now()->addMinutes(30),
            ]);

            // ADR-229: Track checkout session locally for triple recovery
            BillingCheckoutSession::updateOrCreate(
                ['provider_key' => 'stripe', 'provider_session_id' => $sessionId],
                [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'status' => 'created',
                    'metadata' => [
                        'plan_key' => $planKey,
                        'interval' => $interval,
                    ],
                ],
            );
        }

        return new CheckoutResult(
            mode: 'redirect',
            redirectUrl: $session->url,
            subscriptionId: $subscription->id,
        );
    }

    public function handleCallback(array $payload, string $signature): ?array
    {
        return null;
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription = Subscription::where('id', $subscription->id)
                ->lockForUpdate()->first();

            if ($subscription->status === 'cancelled') {
                return; // idempotent
            }

            $subscription->update([
                'status' => 'cancelled',
                'is_current' => null,
            ]);
        });
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
            $intent = $this->callWithRetry(fn () => $this->callStripeCreatePaymentIntent(
                $invoice->amount_due,
                strtolower($invoice->currency ?? config('billing.default_currency', 'EUR')),
                $stripeCustomerId,
                array_merge([
                    'invoice_id' => (string) $invoice->id,
                    'company_id' => (string) $company->id,
                ], $metadata),
                ['idempotency_key' => "collect_invoice_{$invoice->id}"],
            ));

            // ADR-228: Track expected webhook confirmation
            $intentId = $intent->id ?? null;
            if ($intent->status !== 'succeeded' && $intentId) {
                BillingExpectedConfirmation::create([
                    'company_id' => $company->id,
                    'provider_key' => 'stripe',
                    'expected_event_type' => 'payment_intent.succeeded',
                    'provider_reference' => $intentId,
                    'expected_by' => now()->addMinutes(30),
                ]);
            }

            return [
                'provider_payment_id' => $intent->id,
                'amount' => $intent->amount_received ?? $intent->amount,
                'status' => $intent->status === 'succeeded' ? 'succeeded' : 'failed',
                'raw_response' => $intent->toArray(),
            ];
        } catch (\Stripe\Exception\RateLimitException $e) {
            return [
                'provider_payment_id' => null,
                'amount' => $invoice->amount_due,
                'status' => 'rate_limited',
                'raw_response' => ['error' => $e->getMessage()],
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

    /**
     * Charge an invoice using a specific payment method.
     * Used by DunningEngine for fallback across multiple saved methods.
     */
    public function chargeInvoiceWithPaymentMethod(Invoice $invoice, string $paymentMethodId, ?int $amount = null): array
    {
        $company = $invoice->company;
        $this->enforceRateLimit($company->id);
        $this->setApiKey();

        $stripeCustomerId = CompanyPaymentCustomer::where('provider_key', 'stripe')
            ->where('company_id', $company->id)
            ->first()
            ?->provider_customer_id;

        if (! $stripeCustomerId) {
            return ['status' => 'failed', 'error' => 'No Stripe customer found.'];
        }

        $chargeAmount = $amount ?? $invoice->amount_due;

        try {
            $intent = $this->callWithRetry(fn () => $this->callStripeCreatePaymentIntentWithMethod(
                $chargeAmount,
                strtolower($invoice->currency ?? config('billing.default_currency', 'EUR')),
                $stripeCustomerId,
                $paymentMethodId,
                [
                    'invoice_id' => (string) $invoice->id,
                    'company_id' => (string) $company->id,
                    'fallback' => 'true',
                ],
            ));

            return [
                'provider_payment_id' => $intent->id,
                'amount' => $intent->amount_received ?? $intent->amount,
                'status' => $intent->status === 'succeeded' ? 'succeeded' : 'failed',
            ];
        } catch (\Stripe\Exception\RateLimitException $e) {
            return ['status' => 'rate_limited', 'error' => $e->getMessage()];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
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

        // Fallback to DB credentials (admin UI)
        if (! $secret) {
            $module = \App\Core\Billing\PlatformPaymentModule::where('provider_key', 'stripe')->first();
            $secret = $module?->credentials['webhook_secret'] ?? null;
        }

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

    // ── SetupIntent & Payment Method (ADR-225) ──────────

    public function createSetupIntent(Company $company, string $methodType = 'card'): array
    {
        $this->enforceRateLimit($company->id);
        $this->setApiKey();

        $stripeCustomer = $this->ensureStripeCustomer($company);

        $params = [
            'customer' => $stripeCustomer->provider_customer_id,
            'usage' => 'off_session',
            'payment_method_types' => [$methodType],
            'metadata' => ['company_id' => (string) $company->id],
        ];

        try {
            $si = $this->callStripeCreateSetupIntent($params);
        } catch (\Throwable $e) {
            Log::error('[billing] Stripe SetupIntent failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // ADR-228: Track expected webhook confirmation
        $siId = $si->id ?? null;
        if ($siId) {
            BillingExpectedConfirmation::create([
                'company_id' => $company->id,
                'provider_key' => 'stripe',
                'expected_event_type' => 'setup_intent.succeeded',
                'provider_reference' => $siId,
                'expected_by' => now()->addMinutes(30),
            ]);
        }

        return [
            'client_secret' => $si->client_secret,
            'setup_intent_id' => $si->id ?? null,
        ];
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        $this->setApiKey();

        $this->callStripeUpdateCustomer($customerId, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);
    }

    public function retrievePaymentMethod(string $paymentMethodId)
    {
        $this->setApiKey();

        return $this->callStripeRetrievePaymentMethod($paymentMethodId);
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->setApiKey();

        $this->callStripeDetachPaymentMethod($paymentMethodId);
    }

    // ── On-session PaymentIntent (ADR-257) ──────────────

    /**
     * Create a PaymentIntent for on-session payment (user present).
     * Unlike collectInvoice(), this is NOT auto-confirmed — the frontend
     * confirms via Stripe Payment Element which supports card, Apple Pay, etc.
     */
    public function createOnSessionPaymentIntent(
        int $amount,
        string $currency,
        Company $company,
        array $metadata = [],
    ): \Stripe\PaymentIntent {
        $this->enforceRateLimit($company->id);
        $this->setApiKey();

        $stripeCustomer = $this->ensureStripeCustomer($company);

        return $this->callStripeCreateOnSessionPaymentIntent(
            $amount,
            $currency,
            $stripeCustomer->provider_customer_id,
            $metadata,
        );
    }

    // ── Protected wrappers (testable) ────────────────────

    protected function callStripeCreateCheckoutSession(array $params, array $opts = [])
    {
        return \Stripe\Checkout\Session::create($params, $opts);
    }

    protected function callStripeCreateCustomer(Company $company)
    {
        return \Stripe\Customer::create([
            'name' => $company->name,
            'metadata' => ['company_id' => (string) $company->id],
        ]);
    }

    protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = [])
    {
        return \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customerId,
            'confirm' => true,
            'off_session' => true,
            'metadata' => $metadata,
        ], $opts);
    }

    protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata)
    {
        return \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'confirm' => true,
            'off_session' => true,
            'metadata' => $metadata,
        ]);
    }

    protected function callStripeCreateOnSessionPaymentIntent(int $amount, string $currency, string $customerId, array $metadata)
    {
        return \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $customerId,
            'payment_method_types' => ['card', 'sepa_debit'],
            'setup_future_usage' => 'off_session',
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

    protected function callStripeCreateSetupIntent(array $params, array $opts = [])
    {
        return \Stripe\SetupIntent::create($params, $opts);
    }

    protected function callStripeUpdateCustomer(string $customerId, array $params)
    {
        return \Stripe\Customer::update($customerId, $params);
    }

    protected function callStripeRetrievePaymentMethod(string $paymentMethodId)
    {
        return \Stripe\PaymentMethod::retrieve($paymentMethodId);
    }

    protected function callStripeDetachPaymentMethod(string $paymentMethodId)
    {
        return \Stripe\PaymentMethod::retrieve($paymentMethodId)->detach();
    }

    protected function callStripeRetrieveCheckoutSession(string $sessionId)
    {
        return \Stripe\Checkout\Session::retrieve($sessionId);
    }

    protected function callStripeRetrievePaymentIntent(string $paymentIntentId)
    {
        return \Stripe\PaymentIntent::retrieve($paymentIntentId);
    }

    protected function callStripeRetrieveSetupIntent(string $setupIntentId)
    {
        return \Stripe\SetupIntent::retrieve($setupIntentId);
    }

    // ── Recovery helpers (ADR-228) ────────────────────────

    public function retrieveCheckoutSession(string $sessionId)
    {
        $this->setApiKey();

        return $this->callStripeRetrieveCheckoutSession($sessionId);
    }

    public function retrievePaymentIntent(string $paymentIntentId)
    {
        $this->setApiKey();

        return $this->callStripeRetrievePaymentIntent($paymentIntentId);
    }

    public function retrieveSetupIntent(string $setupIntentId)
    {
        $this->setApiKey();

        return $this->callStripeRetrieveSetupIntent($setupIntentId);
    }

    // ── Public helpers ───────────────────────────────────

    public function ensureStripeCustomer(Company $company): CompanyPaymentCustomer
    {
        $existing = CompanyPaymentCustomer::where('company_id', $company->id)
            ->where('provider_key', 'stripe')
            ->first();

        if ($existing) {
            return $existing;
        }

        $customer = $this->callStripeCreateCustomer($company);

        return CompanyPaymentCustomer::create([
            'company_id' => $company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => $customer->id,
        ]);
    }

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

    /**
     * Execute a Stripe API call with automatic retry on 429 (rate limit).
     * Exponential backoff: 1s, 2s, 4s.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function callWithRetry(callable $fn, int $maxRetries = 3): mixed
    {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $fn();
            } catch (\Stripe\Exception\RateLimitException $e) {
                if ($attempt >= $maxRetries) {
                    Log::channel('billing')->error('[stripe] Rate limit exhausted after retries', [
                        'attempts' => $attempt + 1,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }

                $delay = (int) (pow(2, $attempt) * 1_000_000); // 1s, 2s, 4s

                Log::channel('billing')->warning('[stripe] Rate limited (429), backing off', [
                    'attempt' => $attempt + 1,
                    'delay_seconds' => $delay / 1_000_000,
                ]);

                usleep($delay);
            }
        }

        // Unreachable but satisfies static analysis
        throw new \RuntimeException('callWithRetry: unreachable');
    }

    /**
     * Resolve Stripe secret key via module helpers → config fallback.
     */
    protected function resolveSecretKey(): ?string
    {
        $module = \App\Core\Billing\PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $secret = $module?->getStripeSecretKey();

        return $secret ?: (config('billing.stripe.secret') ?: null);
    }

    /**
     * Resolve Stripe publishable key via module helpers → config fallback.
     */
    protected function resolvePublishableKey(): ?string
    {
        $module = \App\Core\Billing\PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $pk = $module?->getStripePublishableKey();

        return $pk ?: (config('billing.stripe.key') ?: null);
    }

    private function setApiKey(): void
    {
        \Stripe\Stripe::setApiKey($this->resolveSecretKey());
    }
}
