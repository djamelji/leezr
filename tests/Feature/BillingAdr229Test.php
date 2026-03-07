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
 * ADR-229: Checkout Session Lifecycle & Triple Recovery.
 *
 * Tests: polling activation, cron recovery, activator idempotency,
 * session ownership, unknown session.
 */
class BillingAdr229Test extends TestCase
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
            'name' => 'ADR229 Co',
            'slug' => 'adr229-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    private function createPendingSubscription(): Subscription
    {
        return Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
            'is_current' => null,
        ]);
    }

    private function fakeStripeSession(Subscription $sub, string $sessionId = 'cs_test_229'): array
    {
        return [
            'id' => $sessionId,
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_229',
            'amount_total' => 2900,
            'currency' => 'eur',
            'metadata' => [
                'company_id' => (string) $this->company->id,
                'subscription_id' => (string) $sub->id,
                'plan_key' => 'pro',
            ],
        ];
    }

    // ── 1: Activator activates subscription ─────────────

    public function test_activator_activates_pending_subscription(): void
    {
        $sub = $this->createPendingSubscription();
        $session = $this->fakeStripeSession($sub);

        $result = CheckoutSessionActivator::activateFromStripeSession($session);

        $this->assertTrue($result->activated);
        $this->assertEquals('checkout_activated', $result->reason);

        $sub->refresh();
        $this->assertContains($sub->status, ['active', 'trialing']);
        $this->assertEquals(1, $sub->is_current);

        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);
    }

    // ── 2: Activator is idempotent ──────────────────────

    public function test_activator_idempotent_double_call(): void
    {
        $sub = $this->createPendingSubscription();
        $session = $this->fakeStripeSession($sub);

        $first = CheckoutSessionActivator::activateFromStripeSession($session);
        $this->assertTrue($first->activated);
        $this->assertFalse($first->idempotent);

        $second = CheckoutSessionActivator::activateFromStripeSession($session);
        $this->assertTrue($second->activated);
        $this->assertTrue($second->idempotent);
        $this->assertEquals('already_activated', $second->reason);

        // Only one invoice created
        $this->assertDatabaseCount('invoices', 1);
    }

    // ── 3: Polling status activates if webhook missed ───

    public function test_polling_status_activates_subscription(): void
    {
        $sub = $this->createPendingSubscription();
        $sessionId = 'cs_poll_' . uniqid();

        // Create local checkout session
        BillingCheckoutSession::create([
            'company_id' => $this->company->id,
            'subscription_id' => $sub->id,
            'provider_key' => 'stripe',
            'provider_session_id' => $sessionId,
            'status' => 'created',
        ]);

        // Bind a mock adapter that returns a completed session
        $this->app->bind(StripePaymentAdapter::class, function () use ($sub, $sessionId) {
            return new class($sub, $sessionId, $this->company) extends StripePaymentAdapter
            {
                private Subscription $sub;
                private string $sessionId;
                private Company $company;

                public function __construct(Subscription $sub, string $sessionId, Company $company)
                {
                    $this->sub = $sub;
                    $this->sessionId = $sessionId;
                    $this->company = $company;
                }

                protected function callStripeRetrieveCheckoutSession(string $id)
                {
                    return \Stripe\Checkout\Session::constructFrom([
                        'id' => $this->sessionId,
                        'status' => 'complete',
                        'payment_status' => 'paid',
                        'payment_intent' => 'pi_poll_test',
                        'amount_total' => 2900,
                        'currency' => 'eur',
                        'metadata' => [
                            'company_id' => (string) $this->company->id,
                            'subscription_id' => (string) $this->sub->id,
                            'plan_key' => 'pro',
                        ],
                    ]);
                }
            };
        });

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/billing/checkout/status?session_id={$sessionId}");

        $response->assertOk();
        $response->assertJson(['status' => 'completed']);

        $sub->refresh();
        $this->assertContains($sub->status, ['active', 'trialing']);
    }

    // ── 4: Foreign session returns 403 ──────────────────

    public function test_foreign_session_returns_403(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        $sub = Subscription::create([
            'company_id' => $otherCompany->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        BillingCheckoutSession::create([
            'company_id' => $otherCompany->id,
            'subscription_id' => $sub->id,
            'provider_key' => 'stripe',
            'provider_session_id' => 'cs_foreign',
            'status' => 'created',
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/checkout/status?session_id=cs_foreign');

        $response->assertStatus(403);
    }

    // ── 5: Unknown session returns 404 ──────────────────

    public function test_unknown_session_returns_404(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/checkout/status?session_id=cs_nonexistent');

        $response->assertStatus(404);
    }

    // ── 6: Cron recover-checkouts dry run ───────────────

    public function test_recover_checkouts_dry_run(): void
    {
        $sub = $this->createPendingSubscription();

        $session = BillingCheckoutSession::create([
            'company_id' => $this->company->id,
            'subscription_id' => $sub->id,
            'provider_key' => 'stripe',
            'provider_session_id' => 'cs_stale_dryrun',
            'status' => 'created',
        ]);

        // Force created_at to be old enough for the 10-minute threshold
        BillingCheckoutSession::where('id', $session->id)
            ->update(['created_at' => now()->subMinutes(15)]);

        $this->artisan('billing:recover-checkouts', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN');
    }

    // ── 7: Activator marks checkout session completed ───

    public function test_activator_marks_checkout_session_completed(): void
    {
        $sub = $this->createPendingSubscription();
        $sessionId = 'cs_mark_test';

        BillingCheckoutSession::create([
            'company_id' => $this->company->id,
            'subscription_id' => $sub->id,
            'provider_key' => 'stripe',
            'provider_session_id' => $sessionId,
            'status' => 'created',
        ]);

        $session = $this->fakeStripeSession($sub, $sessionId);
        CheckoutSessionActivator::activateFromStripeSession($session);

        $this->assertDatabaseHas('billing_checkout_sessions', [
            'provider_session_id' => $sessionId,
            'status' => 'completed',
        ]);
    }
}
