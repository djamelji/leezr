<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\CheckoutSessionActivator;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Staging scenario tests: Triple Recovery (ADR-229).
 *
 * Simulates real-world scenarios where one or more recovery legs fire:
 *   A) Webhook lost → polling activates
 *   B) Webhook lost → cron activates
 *   C) All three legs fire → exactly 1 activation, 1 invoice, 1 payment
 */
class BillingTripleRecoveryTest extends TestCase
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
            'name' => 'Triple Co',
            'slug' => 'triple-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    private function createCheckoutScenario(string $sessionId = 'cs_triple_test'): array
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $local = BillingCheckoutSession::create([
            'company_id' => $this->company->id,
            'subscription_id' => $sub->id,
            'provider_key' => 'stripe',
            'provider_session_id' => $sessionId,
            'status' => 'created',
        ]);

        $stripePayload = [
            'id' => $sessionId,
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_' . $sessionId,
            'amount_total' => 2900,
            'currency' => 'eur',
            'metadata' => [
                'company_id' => (string) $this->company->id,
                'subscription_id' => (string) $sub->id,
                'plan_key' => 'pro',
            ],
        ];

        return [$sub, $local, $stripePayload];
    }

    private function bindMockAdapter(string $sessionId, array $stripePayload): void
    {
        $this->app->bind(StripePaymentAdapter::class, function () use ($sessionId, $stripePayload) {
            return new class($sessionId, $stripePayload) extends StripePaymentAdapter
            {
                private string $sessionId;
                private array $stripePayload;

                public function __construct(string $sessionId, array $stripePayload)
                {
                    $this->sessionId = $sessionId;
                    $this->stripePayload = $stripePayload;
                }

                protected function callStripeRetrieveCheckoutSession(string $id)
                {
                    return \Stripe\Checkout\Session::constructFrom($this->stripePayload);
                }
            };
        });
    }

    // ── A: Webhook lost → polling activates ──────────────

    public function test_scenario_a_webhook_lost_polling_activates(): void
    {
        [$sub, $local, $stripePayload] = $this->createCheckoutScenario('cs_poll_scenario');

        // Webhook never arrives. Company hits success page which polls.
        $this->bindMockAdapter('cs_poll_scenario', $stripePayload);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/checkout/status?session_id=cs_poll_scenario');

        $response->assertOk();
        $response->assertJson(['status' => 'completed']);

        // Subscription activated
        $sub->refresh();
        $this->assertContains($sub->status, ['active', 'trialing']);
        $this->assertEquals(1, $sub->is_current);

        // Company plan synced
        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);

        // Exactly 1 invoice, 1 payment
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);

        // Local checkout session marked completed
        $local->refresh();
        $this->assertEquals('completed', $local->status);
    }

    // ── B: Webhook lost → cron activates ─────────────────

    public function test_scenario_b_webhook_lost_cron_activates(): void
    {
        [$sub, $local, $stripePayload] = $this->createCheckoutScenario('cs_cron_scenario');

        // Force old enough for cron threshold (>10 min)
        BillingCheckoutSession::where('id', $local->id)
            ->update(['created_at' => now()->subMinutes(15)]);

        // Mock Stripe adapter for cron
        $this->bindMockAdapter('cs_cron_scenario', $stripePayload);

        // Run cron recovery (leg 3)
        $this->artisan('billing:recover-checkouts')
            ->assertExitCode(0);

        // Subscription activated
        $sub->refresh();
        $this->assertContains($sub->status, ['active', 'trialing']);
        $this->assertEquals(1, $sub->is_current);

        // Company plan synced
        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);

        // Exactly 1 invoice, 1 payment
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    // ── C: Triple trigger — all three legs fire, idempotent

    public function test_scenario_c_triple_trigger_idempotent(): void
    {
        [$sub, $local, $stripePayload] = $this->createCheckoutScenario('cs_triple_all');

        // Force old enough for cron
        BillingCheckoutSession::where('id', $local->id)
            ->update(['created_at' => now()->subMinutes(15)]);

        // Leg 1: Webhook fires (simulated via direct activator call)
        $webhookResult = CheckoutSessionActivator::activateFromStripeSession($stripePayload);
        $this->assertTrue($webhookResult->activated);
        $this->assertFalse($webhookResult->idempotent);

        // Leg 2: Polling fires (company hits success page)
        $this->bindMockAdapter('cs_triple_all', $stripePayload);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/checkout/status?session_id=cs_triple_all');

        $response->assertOk();
        $response->assertJson(['status' => 'completed']);

        // Leg 3: Cron fires
        $this->artisan('billing:recover-checkouts')
            ->assertExitCode(0);

        // After all three legs: exactly 1 activation
        $sub->refresh();
        $this->assertContains($sub->status, ['active', 'trialing']);
        $this->assertEquals(1, $sub->is_current);

        // Exactly 1 invoice, 1 payment (no duplicates)
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);

        // Exactly 1 local checkout session, marked completed
        $this->assertDatabaseCount('billing_checkout_sessions', 1);
        $local->refresh();
        $this->assertEquals('completed', $local->status);
    }

    // ── D: Concurrent activations serialized by lock ─────

    public function test_scenario_d_concurrent_activations_serialized(): void
    {
        [$sub, $local, $stripePayload] = $this->createCheckoutScenario('cs_concurrent');

        // Simulate two near-simultaneous activator calls
        $result1 = CheckoutSessionActivator::activateFromStripeSession($stripePayload);
        $result2 = CheckoutSessionActivator::activateFromStripeSession($stripePayload);

        // First: real activation. Second: idempotent noop
        $this->assertTrue($result1->activated);
        $this->assertFalse($result1->idempotent);

        $this->assertTrue($result2->activated);
        $this->assertTrue($result2->idempotent);
        $this->assertEquals('already_activated', $result2->reason);

        // No double charge
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    // ── E: Polling after cron is a noop ──────────────────

    public function test_scenario_e_polling_after_cron_returns_cached(): void
    {
        [$sub, $local, $stripePayload] = $this->createCheckoutScenario('cs_cached');

        // Activate via activator (simulates cron having already processed)
        CheckoutSessionActivator::activateFromStripeSession($stripePayload);

        // Local session now completed → polling should return cached result
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/checkout/status?session_id=cs_cached');

        $response->assertOk();
        $response->assertJson(['status' => 'completed']);

        // Still only 1 invoice, 1 payment
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
    }
}
