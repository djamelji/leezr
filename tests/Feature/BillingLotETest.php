<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PaymentMethodResolver;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\Stripe\StripeEventProcessor;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-225: Payment Method UX — LOT E.
 *
 * Tests: SetupIntent creation, saved cards, setup_intent.succeeded webhook,
 * and company-facing invoice retry.
 */
class BillingLotETest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
            'credentials' => ['publishable_key' => 'pk_test_xxx', 'secret_key' => 'sk_test_xxx'],
        ]);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotE Co',
            'slug' => 'lote-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->bindMockAdapter();
    }

    private function bindMockAdapter(): void
    {
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeCreateSetupIntent(array $params, array $opts = [])
                {
                    return \Stripe\SetupIntent::constructFrom([
                        'id' => 'seti_test_' . uniqid(),
                        'client_secret' => 'seti_test_secret_' . uniqid(),
                        'customer' => $params['customer'] ?? null,
                        'status' => 'requires_payment_method',
                    ]);
                }

                protected function callStripeCreateCustomer(Company $company)
                {
                    return \Stripe\Customer::constructFrom([
                        'id' => 'cus_test_' . $company->id,
                        'name' => $company->name,
                    ]);
                }

                protected function callStripeUpdateCustomer(string $customerId, array $params)
                {
                    return \Stripe\Customer::constructFrom([
                        'id' => $customerId,
                    ]);
                }

                protected function callStripeRetrievePaymentMethod(string $paymentMethodId)
                {
                    return \Stripe\PaymentMethod::constructFrom([
                        'id' => $paymentMethodId,
                        'type' => 'card',
                        'card' => [
                            'brand' => 'visa',
                            'last4' => '4242',
                            'exp_month' => 12,
                            'exp_year' => 2028,
                        ],
                    ]);
                }

                protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = [])
                {
                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_test_' . uniqid(),
                        'amount' => $amount,
                        'amount_received' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'metadata' => $metadata,
                    ]);
                }
            };
        });
    }

    // ── E1: SetupIntent endpoint ─────────────────────────

    public function test_setup_intent_returns_client_secret_and_publishable_key(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_existing',
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/billing/setup-intent');

        $response->assertOk();
        $response->assertJsonStructure(['client_secret', 'publishable_key']);
        $this->assertNotNull($response->json('client_secret'));
        $this->assertNotNull($response->json('publishable_key'));
    }

    public function test_setup_intent_creates_stripe_customer_if_not_exists(): void
    {
        // No CompanyPaymentCustomer exists yet
        $this->assertNull(
            CompanyPaymentCustomer::where('company_id', $this->company->id)->first()
        );

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/billing/setup-intent');

        $response->assertOk();

        // ensureStripeCustomer should have created a record
        $customer = CompanyPaymentCustomer::where('company_id', $this->company->id)
            ->where('provider_key', 'stripe')
            ->first();

        $this->assertNotNull($customer, 'Stripe customer should be created');
        $this->assertStringStartsWith('cus_test_', $customer->provider_customer_id);
    }

    // ── E1: Saved cards endpoint ─────────────────────────

    public function test_saved_cards_returns_company_profiles(): void
    {
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'provider_payment_method_id' => 'pm_test_123',
            'label' => 'Visa •••• 4242',
            'is_default' => true,
            'metadata' => [
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2028,
            ],
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/saved-cards');

        $response->assertOk();
        $response->assertJsonCount(1, 'cards');
        $response->assertJsonFragment([
            'brand' => 'visa',
            'last4' => '4242',
            'is_default' => true,
        ]);
    }

    public function test_saved_cards_empty_when_no_profiles(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/saved-cards');

        $response->assertOk();
        $response->assertJsonCount(0, 'cards');
    }

    // ── E2: Webhook — setup_intent.succeeded ─────────────

    public function test_setup_intent_succeeded_webhook_stores_payment_profile(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_webhook_test',
        ]);

        $processor = app(StripeEventProcessor::class);

        $result = $processor->process([
            'type' => 'setup_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'seti_webhook_test',
                    'payment_method' => 'pm_webhook_test',
                    'customer' => 'cus_webhook_test',
                    'metadata' => ['company_id' => (string) $this->company->id],
                ],
            ],
        ]);

        $this->assertTrue($result->handled);
        $this->assertEquals('setup_intent_synced', $result->action);

        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)
            ->where('provider_payment_method_id', 'pm_webhook_test')
            ->first();

        $this->assertNotNull($profile, 'Payment profile should be stored');
        $this->assertEquals('visa', $profile->metadata['brand']);
        $this->assertEquals('4242', $profile->metadata['last4']);
    }

    public function test_setup_intent_succeeded_webhook_sets_default(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_default_test',
        ]);

        // Create an existing default card
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'provider_payment_method_id' => 'pm_old',
            'label' => 'Old Card',
            'is_default' => true,
            'metadata' => ['brand' => 'mastercard', 'last4' => '1234'],
        ]);

        $processor = app(StripeEventProcessor::class);

        $processor->process([
            'type' => 'setup_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'seti_default_test',
                    'payment_method' => 'pm_new',
                    'customer' => 'cus_default_test',
                    'metadata' => ['company_id' => (string) $this->company->id],
                ],
            ],
        ]);

        // Old card should no longer be default
        $old = CompanyPaymentProfile::where('provider_payment_method_id', 'pm_old')->first();
        $this->assertFalse($old->is_default, 'Old card should not be default');

        // New card should be default
        $new = CompanyPaymentProfile::where('provider_payment_method_id', 'pm_new')->first();
        $this->assertTrue($new->is_default, 'New card should be default');
    }

    // ── E1: Invoice retry ────────────────────────────────

    public function test_company_retry_overdue_invoice(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_retry_test',
        ]);

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($draft);
        $invoice->update([
            'status' => 'overdue',
            'next_retry_at' => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson("/api/billing/invoices/{$invoice->id}/retry");

        $response->assertOk();
        $response->assertJsonStructure(['result']);
    }

    public function test_company_cannot_retry_paid_invoice(): void
    {
        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($draft);

        // Force status to 'paid' — cannot be retried
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson("/api/billing/invoices/{$invoice->id}/retry");

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Only open or overdue invoices can be paid.']);
    }

    // ── P1: Setup Intent — error handling ─────────────────

    public function test_setup_intent_returns_422_when_not_configured(): void
    {
        // Delete the PlatformPaymentModule seeded in setUp
        PlatformPaymentModule::where('provider_key', 'stripe')->delete();

        $response = $this->actAs()->postJson('/api/billing/setup-intent');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Stripe is not configured correctly.');
    }

    public function test_setup_intent_returns_422_when_secret_key_wrong_format(): void
    {
        // Set credentials with a restricted key (mk_) instead of sk_
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $module->credentials = ['publishable_key' => 'pk_test_xxx', 'secret_key' => 'mk_bad_key'];
        $module->save();

        $response = $this->actAs()->postJson('/api/billing/setup-intent');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Stripe is not configured correctly.');
    }

    public function test_setup_intent_returns_503_on_stripe_error(): void
    {
        // Bind an adapter that throws on createSetupIntent
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                public function createSetupIntent(\App\Core\Models\Company $company, string $methodType = 'card'): array
                {
                    throw new \RuntimeException('Stripe connection failed');
                }
            };
        });

        $response = $this->actAs()->postJson('/api/billing/setup-intent');

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Payment service temporarily unavailable.');
    }

    public function test_setup_intent_503_when_adapter_sdk_throws(): void
    {
        // Bind adapter where SDK call itself throws (simulates Stripe API error)
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeCreateSetupIntent(array $params, array $opts = [])
                {
                    throw new \Stripe\Exception\ApiConnectionException('Network error');
                }

                protected function callStripeCreateCustomer(Company $company)
                {
                    return \Stripe\Customer::constructFrom(['id' => 'cus_sdk_err']);
                }

                protected function callStripeUpdateCustomer(string $customerId, array $params)
                {
                    return \Stripe\Customer::constructFrom(['id' => $customerId]);
                }
            };
        });

        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_sdk_err',
        ]);

        $response = $this->actAs()->postJson('/api/billing/setup-intent');

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Payment service temporarily unavailable.');
    }

    // ── P2: Card management ─────────────────

    public function test_delete_card_removes_profile(): void
    {
        $profile = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'Visa •••• 4242',
            'is_default' => false,
            'metadata' => ['brand' => 'visa', 'last4' => '4242'],
        ]);

        $response = $this->actAs()->deleteJson("/api/billing/saved-cards/{$profile->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Card removed.');

        $this->assertDatabaseMissing('company_payment_profiles', ['id' => $profile->id]);
    }

    public function test_delete_card_404_other_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        $profile = CompanyPaymentProfile::create([
            'company_id' => $otherCompany->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'Visa •••• 1234',
            'is_default' => false,
            'metadata' => ['brand' => 'visa', 'last4' => '1234'],
        ]);

        $response = $this->actAs()->deleteJson("/api/billing/saved-cards/{$profile->id}");

        $response->assertStatus(404);
    }

    public function test_delete_default_promotes_next(): void
    {
        $defaultCard = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'Visa •••• 4242',
            'is_default' => true,
            'metadata' => ['brand' => 'visa', 'last4' => '4242'],
        ]);

        $nextCard = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'MC •••• 5555',
            'is_default' => false,
            'metadata' => ['brand' => 'mastercard', 'last4' => '5555'],
        ]);

        $this->actAs()->deleteJson("/api/billing/saved-cards/{$defaultCard->id}")
            ->assertOk();

        $nextCard->refresh();
        $this->assertTrue($nextCard->is_default);
    }

    public function test_set_default_card(): void
    {
        $card1 = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'Visa •••• 4242',
            'is_default' => true,
            'metadata' => ['brand' => 'visa', 'last4' => '4242'],
        ]);

        $card2 = CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'MC •••• 5555',
            'is_default' => false,
            'metadata' => ['brand' => 'mastercard', 'last4' => '5555'],
        ]);

        $response = $this->actAs()->putJson("/api/billing/saved-cards/{$card2->id}/default");

        $response->assertOk()
            ->assertJsonPath('message', 'Default card updated.');

        $card1->refresh();
        $card2->refresh();
        $this->assertFalse($card1->is_default);
        $this->assertTrue($card2->is_default);
    }

    public function test_set_default_404_other_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co 2',
            'slug' => 'other-co-2',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        $profile = CompanyPaymentProfile::create([
            'company_id' => $otherCompany->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'label' => 'Visa •••• 9999',
            'is_default' => false,
            'metadata' => ['brand' => 'visa', 'last4' => '9999'],
        ]);

        $response = $this->actAs()->putJson("/api/billing/saved-cards/{$profile->id}/default");

        $response->assertStatus(404);
    }

    // ── P2c: Retry returns message ─────────────────

    public function test_retry_returns_enriched_message(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_msg_test',
        ]);

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($draft);
        $invoice->update([
            'status' => 'overdue',
            'next_retry_at' => now(),
        ]);

        $response = $this->actAs()->postJson("/api/billing/invoices/{$invoice->id}/retry");

        $response->assertOk()
            ->assertJsonStructure(['result', 'message']);

        // message should be a known string, not null
        $this->assertNotEmpty($response->json('message'));
    }

    // ── P4: SEPA — method type validation ─────────────────

    public function test_setup_intent_rejects_invalid_method(): void
    {
        $response = $this->actAs()->postJson('/api/billing/setup-intent', [
            'method' => 'bitcoin',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Invalid payment method type.');
    }

    public function test_setup_intent_accepts_sepa_debit(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_sepa_test',
        ]);

        $response = $this->actAs()->postJson('/api/billing/setup-intent', [
            'method' => 'sepa_debit',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['client_secret', 'publishable_key']);
    }

    // ── P4c: SEPA webhook ─────────────────

    public function test_webhook_sepa_stores_profile(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_sepa_wh',
        ]);

        // Bind adapter that returns SEPA PM type
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeRetrievePaymentMethod(string $paymentMethodId)
                {
                    return \Stripe\PaymentMethod::constructFrom([
                        'id' => $paymentMethodId,
                        'type' => 'sepa_debit',
                        'sepa_debit' => [
                            'bank_code' => 'BNPA',
                            'country' => 'FR',
                            'last4' => '3456',
                        ],
                    ]);
                }

                protected function callStripeCreateSetupIntent(array $params, array $opts = [])
                {
                    return \Stripe\SetupIntent::constructFrom([
                        'id' => 'seti_sepa_' . uniqid(),
                        'client_secret' => 'seti_sepa_secret',
                        'customer' => $params['customer'] ?? null,
                        'status' => 'requires_payment_method',
                    ]);
                }

                protected function callStripeCreateCustomer(\App\Core\Models\Company $company)
                {
                    return \Stripe\Customer::constructFrom([
                        'id' => 'cus_sepa_' . $company->id,
                    ]);
                }

                protected function callStripeUpdateCustomer(string $customerId, array $params)
                {
                    return \Stripe\Customer::constructFrom(['id' => $customerId]);
                }
            };
        });

        $processor = app(StripeEventProcessor::class);

        $processor->process([
            'type' => 'setup_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'seti_sepa_wh',
                    'payment_method' => 'pm_sepa_test',
                    'customer' => 'cus_sepa_wh',
                    'mandate' => 'mandate_test_123',
                    'metadata' => ['company_id' => (string) $this->company->id],
                ],
            ],
        ]);

        $profile = CompanyPaymentProfile::where('company_id', $this->company->id)
            ->where('provider_payment_method_id', 'pm_sepa_test')
            ->first();

        $this->assertNotNull($profile, 'SEPA profile should be stored');
        $this->assertEquals('sepa_debit', $profile->method_key);
        $this->assertEquals('3456', $profile->metadata['last4']);
        $this->assertEquals('FR', $profile->metadata['country']);
    }

    // ── P5: Billing Day ─────────────────

    public function test_set_billing_day(): void
    {
        $response = $this->actAs()->putJson('/api/billing/subscription/billing-day', [
            'billing_anchor_day' => 15,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Billing day updated.')
            ->assertJsonPath('billing_anchor_day', 15);

        $this->subscription->refresh();
        $this->assertEquals(15, $this->subscription->billing_anchor_day);
    }

    public function test_set_billing_day_invalid_rejected(): void
    {
        $response = $this->actAs()->putJson('/api/billing/subscription/billing-day', [
            'billing_anchor_day' => 13,
        ]);

        $response->assertStatus(422);
    }

    // ── P8: Saved cards returns method_key ──

    public function test_saved_cards_returns_method_key(): void
    {
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'label' => 'SEPA •••• 1234',
            'is_default' => true,
            'metadata' => ['type' => 'sepa_debit', 'last4' => '1234'],
        ]);

        $response = $this->actAs()->getJson('/api/billing/saved-cards');

        $response->assertOk()
            ->assertJsonPath('cards.0.method_key', 'sepa_debit');
    }

    // ── Fallback: Payment method cascade ──

    public function test_payment_fallback_to_second_method(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_fallback',
        ]);

        // Card (default) — will fail
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'provider_payment_method_id' => 'pm_card_fail',
            'label' => 'Visa •••• 0000',
            'is_default' => true,
            'metadata' => ['brand' => 'visa', 'last4' => '0000'],
        ]);

        // SEPA (non-default) — will succeed
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'provider_payment_method_id' => 'pm_sepa_ok',
            'label' => 'SEPA •••• 1234',
            'is_default' => false,
            'metadata' => ['type' => 'sepa_debit', 'last4' => '1234'],
        ]);

        // Bind adapter: card fails, SEPA succeeds
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata)
                {
                    if ($paymentMethodId === 'pm_card_fail') {
                        throw new \Stripe\Exception\CardException('Card declined', null, null, null, null, null);
                    }

                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_sepa_' . uniqid(),
                        'amount' => $amount,
                        'amount_received' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeCreateCustomer(Company $company)
                {
                    return \Stripe\Customer::constructFrom(['id' => 'cus_fallback']);
                }
            };
        });

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($draft);
        $invoice->update(['status' => 'overdue', 'next_retry_at' => now()]);

        $result = DunningEngine::retrySingleInvoice($invoice);

        // Should have fallen back to SEPA and succeeded
        $this->assertEquals('provider_attempted', $result);
    }

    public function test_payment_stops_after_success(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_stop',
        ]);

        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'provider_payment_method_id' => 'pm_first_ok',
            'label' => 'Visa •••• 4242',
            'is_default' => true,
            'metadata' => ['brand' => 'visa', 'last4' => '4242'],
        ]);

        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'provider_payment_method_id' => 'pm_second_never',
            'label' => 'SEPA •••• 9999',
            'is_default' => false,
            'metadata' => ['type' => 'sepa_debit', 'last4' => '9999'],
        ]);

        // Track which PMs are attempted via a shared object
        $tracker = new \stdClass();
        $tracker->attempted = [];

        $this->app->bind(StripePaymentAdapter::class, function () use ($tracker) {
            return new class($tracker) extends StripePaymentAdapter
            {
                private \stdClass $tracker;

                public function __construct(\stdClass $tracker)
                {
                    $this->tracker = $tracker;
                }

                protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata)
                {
                    $this->tracker->attempted[] = $paymentMethodId;

                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => 'pi_' . uniqid(),
                        'amount' => $amount,
                        'amount_received' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'metadata' => $metadata,
                    ]);
                }

                protected function callStripeCreateCustomer(Company $company)
                {
                    return \Stripe\Customer::constructFrom(['id' => 'cus_stop']);
                }
            };
        });

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($draft);
        $invoice->update(['status' => 'overdue', 'next_retry_at' => now()]);

        DunningEngine::retrySingleInvoice($invoice);

        // Only the first (default) method should have been attempted
        $this->assertCount(1, $tracker->attempted);
        $this->assertEquals('pm_first_ok', $tracker->attempted[0]);
    }

    public function test_payment_all_methods_fail_falls_back_to_wallet(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_allfail',
        ]);

        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'provider_payment_method_id' => 'pm_fail_1',
            'label' => 'Visa •••• 0000',
            'is_default' => true,
            'metadata' => ['brand' => 'visa', 'last4' => '0000'],
        ]);

        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'sepa_debit',
            'provider_payment_method_id' => 'pm_fail_2',
            'label' => 'SEPA •••• 5555',
            'is_default' => false,
            'metadata' => ['type' => 'sepa_debit', 'last4' => '5555'],
        ]);

        // Bind adapter: all methods fail
        $this->app->bind(StripePaymentAdapter::class, function () {
            return new class extends StripePaymentAdapter
            {
                protected function callStripeCreatePaymentIntentWithMethod(int $amount, string $currency, string $customerId, string $paymentMethodId, array $metadata)
                {
                    throw new \Stripe\Exception\CardException('Declined', null, null, null, null, null);
                }

                protected function callStripeCreateCustomer(Company $company)
                {
                    return \Stripe\Customer::constructFrom(['id' => 'cus_allfail']);
                }
            };
        });

        $draft = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($draft, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($draft);
        $invoice->update(['status' => 'overdue', 'next_retry_at' => now()]);

        $result = DunningEngine::retrySingleInvoice($invoice);

        // All provider methods failed → wallet fallback (no wallet balance → rescheduled)
        $this->assertContains($result, ['retried', 'exhausted']);

        // Invoice should still exist (not paid by provider)
        $invoice->refresh();
        $this->assertNotEquals('paid', $invoice->status);
    }

    // ── Stripe environment configuration ──

    public function test_stripe_module_test_mode_keys_used(): void
    {
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $module->credentials = [
            'mode' => 'test',
            'test_publishable_key' => 'pk_test_env_test',
            'test_secret_key' => 'sk_test_env_test',
            'live_publishable_key' => 'pk_live_env_live',
            'live_secret_key' => 'sk_live_env_live',
            'webhook_secret' => 'whsec_xxx',
        ];
        $module->save();

        $this->assertEquals('test', $module->getStripeMode());
        $this->assertEquals('pk_test_env_test', $module->getStripePublishableKey());
        $this->assertEquals('sk_test_env_test', $module->getStripeSecretKey());
    }

    public function test_stripe_module_live_mode_keys_used(): void
    {
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $module->credentials = [
            'mode' => 'live',
            'test_publishable_key' => 'pk_test_env_test',
            'test_secret_key' => 'sk_test_env_test',
            'live_publishable_key' => 'pk_live_env_live',
            'live_secret_key' => 'sk_live_env_live',
            'webhook_secret' => 'whsec_xxx',
        ];
        $module->save();

        $this->assertEquals('live', $module->getStripeMode());
        $this->assertEquals('pk_live_env_live', $module->getStripePublishableKey());
        $this->assertEquals('sk_live_env_live', $module->getStripeSecretKey());
    }

    public function test_stripe_misconfigured_returns_422(): void
    {
        // Mode=live but only test keys present
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $module->credentials = [
            'mode' => 'live',
            'test_publishable_key' => 'pk_test_xxx',
            'test_secret_key' => 'sk_test_xxx',
        ];
        $module->save();

        $response = $this->actAs()->postJson('/api/billing/setup-intent');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Stripe is not configured correctly.');
    }

    public function test_stripe_adapter_resolves_keys_from_module(): void
    {
        // Set mode-based credentials and verify setup-intent returns the correct publishable key
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $module->credentials = [
            'mode' => 'test',
            'test_publishable_key' => 'pk_test_adapter',
            'test_secret_key' => 'sk_test_adapter',
        ];
        $module->save();

        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_adapter_test',
        ]);

        $response = $this->actAs()->postJson('/api/billing/setup-intent');

        $response->assertOk()
            ->assertJsonPath('publishable_key', 'pk_test_adapter');
    }

    public function test_stripe_configuration_status(): void
    {
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();

        // Active with correct keys
        $module->credentials = ['mode' => 'test', 'test_publishable_key' => 'pk_test_x', 'test_secret_key' => 'sk_test_x'];
        $module->save();
        $this->assertEquals('active', $module->getConfigurationStatus());

        // Misconfigured — wrong prefix
        $module->credentials = ['mode' => 'test', 'test_publishable_key' => 'pk_live_wrong', 'test_secret_key' => 'sk_test_x'];
        $module->save();
        $this->assertEquals('misconfigured', $module->getConfigurationStatus());

        // Misconfigured — missing keys for mode
        $module->credentials = ['mode' => 'live'];
        $module->save();
        $this->assertEquals('misconfigured', $module->getConfigurationStatus());

        // Disabled
        $module->is_active = false;
        $module->save();
        $this->assertEquals('disabled', $module->getConfigurationStatus());
    }

    public function test_stripe_backward_compatible_flat_keys(): void
    {
        // Old format (flat keys without mode) should still work
        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $module->credentials = [
            'publishable_key' => 'pk_test_old_format',
            'secret_key' => 'sk_test_old_format',
        ];
        $module->save();

        // Defaults to test mode, falls back to flat keys
        $this->assertEquals('test', $module->getStripeMode());
        $this->assertEquals('pk_test_old_format', $module->getStripePublishableKey());
        $this->assertEquals('sk_test_old_format', $module->getStripeSecretKey());
    }

    private function actAs(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }
}
