<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\CheckoutResult;
use App\Core\Billing\NullPaymentGateway;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use App\Modules\Platform\Billing\UseCases\ApproveSubscriptionUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-230/231: Signup Funnel (LOT B).
 *
 * Tests: billing interval, gateway drivers, registration subscriptions,
 * checkout response, ApproveSubscription is_current.
 */
class BillingLotBTest extends TestCase
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

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotB Co',
            'slug' => 'lotb-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    // ── B1: interval parameter ──────────────────────────────

    public function test_null_gateway_passes_interval_to_subscription(): void
    {
        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'yearly');

        $sub = Subscription::find($result->subscriptionId);
        $this->assertNotNull($sub);
        $this->assertEquals('yearly', $sub->interval);
    }

    public function test_internal_adapter_passes_interval_to_subscription(): void
    {
        $adapter = new InternalPaymentAdapter();
        $result = $adapter->createCheckout($this->company, 'pro', 'yearly');

        $sub = Subscription::find($result->subscriptionId);
        $this->assertNotNull($sub);
        $this->assertEquals('yearly', $sub->interval);
    }

    public function test_null_gateway_sets_is_current_for_trialing(): void
    {
        // Pro plan has trial_days=14
        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro');

        $sub = Subscription::find($result->subscriptionId);
        $this->assertEquals('trialing', $sub->status);
        $this->assertEquals(1, $sub->is_current);
    }

    public function test_internal_adapter_sets_is_current_for_trialing(): void
    {
        $adapter = new InternalPaymentAdapter();
        $result = $adapter->createCheckout($this->company, 'pro');

        $sub = Subscription::find($result->subscriptionId);
        $this->assertEquals('trialing', $sub->status);
        $this->assertEquals(1, $sub->is_current);
    }

    public function test_null_gateway_deactivates_previous_is_current(): void
    {
        // Create existing current subscription
        $existing = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'status' => 'active',
            'provider' => 'null',
            'is_current' => 1,
        ]);

        $gateway = new NullPaymentGateway();
        $gateway->createCheckout($this->company, 'pro');

        $existing->refresh();
        $this->assertNull($existing->is_current);
    }

    // ── B2: gateway drivers ─────────────────────────────────

    public function test_gateway_manager_resolves_stripe_driver(): void
    {
        $manager = app(PaymentGatewayManager::class);
        $driver = $manager->driver('stripe');

        $this->assertInstanceOf(\App\Core\Billing\Adapters\StripePaymentAdapter::class, $driver);
    }

    public function test_gateway_manager_resolves_internal_driver(): void
    {
        $manager = app(PaymentGatewayManager::class);
        $driver = $manager->driver('internal');

        $this->assertInstanceOf(InternalPaymentAdapter::class, $driver);
    }

    // ── B5: registration creates subscription ───────────────

    public function test_register_with_starter_creates_active_subscription(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Starter',
            'last_name' => 'User',
            'email' => 'starter@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Starter Co',
            'jobdomain_key' => 'logistique',
            'plan_key' => 'starter',
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Starter Co')->first();
        $this->assertNotNull($company);

        $sub = Subscription::where('company_id', $company->id)->first();
        $this->assertNotNull($sub, 'Subscription should be created at registration');
        $this->assertEquals('active', $sub->status);
        $this->assertEquals('starter', $sub->plan_key);
        $this->assertEquals(1, $sub->is_current);
    }

    public function test_register_with_pro_creates_trialing_subscription(): void
    {
        // ADR-303: skip checkout collection in this test (no Stripe mock)
        \App\Core\Billing\PlatformBillingPolicy::instance()->update(['trial_requires_payment_method' => false]);

        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Pro',
            'last_name' => 'User',
            'email' => 'pro@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Pro Co',
            'jobdomain_key' => 'logistique',
            'plan_key' => 'pro',
            'billing_interval' => 'yearly',
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Pro Co')->first();
        $sub = Subscription::where('company_id', $company->id)->first();

        $this->assertNotNull($sub);
        $this->assertEquals('trialing', $sub->status);
        $this->assertEquals('pro', $sub->plan_key);
        $this->assertEquals('yearly', $sub->interval);
        $this->assertEquals(1, $sub->is_current);
        $this->assertNotNull($sub->trial_ends_at);
    }

    public function test_register_with_no_plan_defaults_to_starter(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Default',
            'last_name' => 'User',
            'email' => 'default@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Default Co',
            'jobdomain_key' => 'logistique',
        ]);

        $response->assertStatus(201);

        $company = Company::where('name', 'Default Co')->first();
        $sub = Subscription::where('company_id', $company->id)->first();

        $this->assertNotNull($sub);
        $this->assertEquals('active', $sub->status);
        $this->assertEquals('starter', $sub->plan_key);
        $this->assertEquals(1, $sub->is_current);
    }

    // ── B6: auth response checkout ──────────────────────────

    public function test_register_response_has_no_checkout_for_free_plan(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Free',
            'last_name' => 'User',
            'email' => 'free@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Free Co',
            'jobdomain_key' => 'logistique',
            'plan_key' => 'starter',
        ]);

        $response->assertStatus(201);
        $response->assertJsonMissing(['checkout']);
    }

    public function test_register_response_has_no_checkout_for_trial_plan(): void
    {
        // ADR-303: when trial_requires_payment_method is false, no checkout in response
        \App\Core\Billing\PlatformBillingPolicy::instance()->update(['trial_requires_payment_method' => false]);

        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Trial',
            'last_name' => 'User',
            'email' => 'trial@example.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Trial Co',
            'jobdomain_key' => 'logistique',
            'plan_key' => 'pro',
        ]);

        $response->assertStatus(201);
        $response->assertJsonMissing(['checkout']);
    }

    // ── B8: ApproveSubscriptionUseCase is_current ───────────

    public function test_approve_subscription_sets_is_current(): void
    {
        // Create pending subscription
        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'pending',
            'provider' => 'internal',
        ]);

        $useCase = app(ApproveSubscriptionUseCase::class);
        $result = $useCase->execute($subscription->id);

        $this->assertEquals('active', $result->status);
        $this->assertEquals(1, $result->is_current);
    }

    public function test_approve_subscription_clears_previous_is_current(): void
    {
        // Create existing current subscription
        $existing = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
        ]);

        // Create pending subscription
        $pending = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'status' => 'pending',
            'provider' => 'internal',
        ]);

        $useCase = app(ApproveSubscriptionUseCase::class);
        $result = $useCase->execute($pending->id);

        $existing->refresh();
        $this->assertNull($existing->is_current);
        $this->assertEquals('expired', $existing->status);
        $this->assertEquals(1, $result->is_current);
    }
}
