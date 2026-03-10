<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CheckoutResult;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\DTOs\WebhookHandlingResult;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Stripe\StripeEventProcessor;
use App\Core\Billing\Subscription;
use App\Core\Billing\SubscriptionCanceller;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-220/221/222: Billing Production Foundations (LOT A).
 *
 * Tests: addon subscriptions, pending_payment status, is_current uniqueness,
 * Stripe checkout, cancel, webhook handler, collectInvoice idempotency.
 */
class BillingLotATest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

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
        ]);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotA Co',
            'slug' => 'lota-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    // ── A1: company_addon_subscriptions ──────────────────

    public function test_addon_subscription_unique_per_company_module(): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_fleet',
            'amount_cents' => 1500,
            'activated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_fleet',
            'amount_cents' => 2000,
            'activated_at' => now(),
        ]);
    }

    public function test_addon_subscription_active_scope(): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_fleet',
            'amount_cents' => 1500,
            'activated_at' => now(),
        ]);

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_analytics',
            'amount_cents' => 900,
            'activated_at' => now()->subDays(10),
            'deactivated_at' => now()->subDay(),
        ]);

        $this->assertCount(1, CompanyAddonSubscription::active()->get());
    }

    // ── A2: pending_payment status ──────────────────────

    public function test_pending_payment_excluded_from_scope_current(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $this->assertCount(0, Subscription::where('company_id', $this->company->id)->current()->get());
    }

    public function test_pending_payment_excluded_from_scope_active(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $this->assertTrue($sub->isPendingPayment());
        $this->assertFalse($sub->isActive());
        $this->assertCount(0, Subscription::where('company_id', $this->company->id)->active()->get());
    }

    // ── A3: is_current uniqueness ───────────────────────

    public function test_is_current_unique_constraint_prevents_two_current(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);

        $this->expectException(QueryException::class);

        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'business',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);
    }

    public function test_multiple_null_is_current_allowed(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'cancelled',
            'provider' => 'stripe',
            'is_current' => null,
        ]);

        $sub2 = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'business',
            'status' => 'cancelled',
            'provider' => 'stripe',
            'is_current' => null,
        ]);

        // Should not throw — MySQL ignores NULL in unique indexes
        $this->assertNotNull($sub2->id);
    }

    public function test_canceller_clears_is_current(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
        ]);

        // Default policy is end_of_period, so set immediate for this test
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'immediate']);

        SubscriptionCanceller::cancel($this->company, 'test-cancel-key');

        $sub->refresh();
        $this->assertEquals('cancelled', $sub->status);
        $this->assertNull($sub->is_current);
    }

    public function test_dunning_failure_clears_is_current(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
        ]);

        $policy = PlatformBillingPolicy::instance();

        DunningEngine::applyFailureAction($this->company, $policy);

        $sub->refresh();
        $this->assertNull($sub->is_current);
    }

    // ── A4: createCheckout — embedded SetupIntent (ADR-302) ──

    public function test_create_checkout_returns_embedded_mode(): void
    {
        $adapter = $this->createMockStripeAdapter();

        $result = $adapter->createCheckout($this->company, 'pro');

        $this->assertEquals('embedded', $result->mode);
        $this->assertNotNull($result->clientSecret);
        $this->assertNotNull($result->subscriptionId);
    }

    public function test_create_checkout_creates_pending_payment_subscription(): void
    {
        $adapter = $this->createMockStripeAdapter();

        $result = $adapter->createCheckout($this->company, 'pro');

        $sub = Subscription::find($result->subscriptionId);
        $this->assertEquals('pending_payment', $sub->status);
        $this->assertEquals('stripe', $sub->provider);
        $this->assertEquals(1, $sub->is_current);
    }

    public function test_create_checkout_setup_intent_has_correct_metadata(): void
    {
        $capture = new \ArrayObject;

        $adapter = new class($capture) extends StripePaymentAdapter
        {
            private \ArrayObject $capture;

            public function __construct(\ArrayObject $capture)
            {
                $this->capture = $capture;
            }

            protected function callStripeCreateSetupIntent(array $params, array $opts = [])
            {
                $this->capture['params'] = $params;

                $si = new \stdClass;
                $si->client_secret = 'seti_mock_secret';
                $si->id = 'seti_mock_id';

                return $si;
            }

            protected function callStripeCreateCustomer(Company $company)
            {
                $customer = new \stdClass;
                $customer->id = 'cus_mock_meta';

                return $customer;
            }

            private function setApiKey(): void {}
        };

        $adapter->createCheckout($this->company, 'pro');

        $params = $capture['params'];
        $this->assertEquals((string) $this->company->id, $params['metadata']['company_id']);
        $this->assertEquals('pro', $params['metadata']['plan_key']);
        $this->assertArrayHasKey('subscription_id', $params['metadata']);
    }

    // ── A5: cancelSubscription ──────────────────────────

    public function test_stripe_cancel_sets_cancelled_and_clears_is_current(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);

        $adapter = new StripePaymentAdapter;
        $adapter->cancelSubscription($sub);

        $sub->refresh();
        $this->assertEquals('cancelled', $sub->status);
        $this->assertNull($sub->is_current);
    }

    public function test_stripe_cancel_idempotent(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'cancelled',
            'provider' => 'stripe',
            'is_current' => null,
        ]);

        $adapter = new StripePaymentAdapter;
        $adapter->cancelSubscription($sub);

        $sub->refresh();
        $this->assertEquals('cancelled', $sub->status);
    }

    // ── A6: checkout.session.completed webhook ──────────

    public function test_checkout_webhook_activates_subscription(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
            'is_current' => null,
        ]);

        $processor = app(StripeEventProcessor::class);

        $result = $processor->process([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'metadata' => [
                        'subscription_id' => (string) $sub->id,
                        'company_id' => (string) $this->company->id,
                        'plan_key' => 'pro',
                    ],
                    'payment_intent' => 'pi_test_checkout',
                    'amount_total' => 2900,
                    'currency' => 'eur',
                ],
            ],
        ]);

        $this->assertTrue($result->handled);
        $this->assertEquals('checkout_activated', $result->action);

        $sub->refresh();
        // Pro plan has trial_days=14, so status is 'trialing'
        $this->assertContains($sub->status, ['active', 'trialing']);
        $this->assertEquals(1, $sub->is_current);

        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);
    }

    public function test_checkout_webhook_creates_invoice_and_payment(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
            'is_current' => null,
        ]);

        $processor = app(StripeEventProcessor::class);
        $processor->process([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'metadata' => [
                        'subscription_id' => (string) $sub->id,
                        'company_id' => (string) $this->company->id,
                        'plan_key' => 'pro',
                    ],
                    'payment_intent' => 'pi_test_invoice',
                    'amount_total' => 2900,
                    'currency' => 'eur',
                ],
            ],
        ]);

        // Invoice created and finalized
        $invoice = Invoice::where('company_id', $this->company->id)
            ->where('subscription_id', $sub->id)
            ->first();
        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->finalized_at);

        // Payment record created
        $payment = Payment::where('provider_payment_id', 'pi_test_invoice')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('succeeded', $payment->status);
        $this->assertEquals(2900, $payment->amount);
    }

    public function test_checkout_webhook_idempotent(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);

        $processor = app(StripeEventProcessor::class);

        $result = $processor->process([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'metadata' => [
                        'subscription_id' => (string) $sub->id,
                        'company_id' => (string) $this->company->id,
                        'plan_key' => 'pro',
                    ],
                    'payment_intent' => 'pi_test_idempotent',
                    'amount_total' => 2900,
                    'currency' => 'eur',
                ],
            ],
        ]);

        $this->assertTrue($result->handled);
        $this->assertEquals('already_activated', $result->action);
    }

    // ── A7: collectInvoice idempotency key ──────────────

    public function test_collect_invoice_passes_idempotency_key(): void
    {
        $capture = new \ArrayObject;

        $adapter = new class($capture) extends StripePaymentAdapter
        {
            private \ArrayObject $capture;

            public function __construct(\ArrayObject $capture)
            {
                $this->capture = $capture;
            }

            protected function callStripeCreatePaymentIntent(int $amount, string $currency, string $customerId, array $metadata, array $opts = [])
            {
                $this->capture['opts'] = $opts;

                return new class($amount) {
                    public string $id = 'pi_mock_idempotent';
                    public int $amount;
                    public int $amount_received;
                    public string $status = 'succeeded';

                    public function __construct(int $amount)
                    {
                        $this->amount = $amount;
                        $this->amount_received = $amount;
                    }

                    public function toArray(): array
                    {
                        return [];
                    }
                };
            }

            private function setApiKey(): void {}

            private function enforceRateLimit(?int $companyId = null): void {}
        };

        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test_idempotent',
        ]);

        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'active',
            'provider' => 'stripe',
        ]);

        $invoice = InvoiceIssuer::createDraft($this->company, $sub->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900, 1);
        $invoice = InvoiceIssuer::finalize($invoice);

        $adapter->collectInvoice($invoice, $this->company);

        $this->assertEquals("collect_invoice_{$invoice->id}", $capture['opts']['idempotency_key']);
    }

    // ── Helpers ─────────────────────────────────────────

    private function createMockStripeAdapter(): StripePaymentAdapter
    {
        return new class extends StripePaymentAdapter
        {
            protected function callStripeCreateSetupIntent(array $params, array $opts = [])
            {
                $si = new \stdClass;
                $si->client_secret = 'seti_mock_secret';
                $si->id = 'seti_mock_id';

                return $si;
            }

            protected function callStripeCreateCustomer(Company $company)
            {
                $customer = new \stdClass;
                $customer->id = 'cus_mock_' . $company->id;

                return $customer;
            }

            protected function resolvePublishableKey(): ?string
            {
                return 'pk_test_mock';
            }

            private function setApiKey(): void {}
        };
    }
}
