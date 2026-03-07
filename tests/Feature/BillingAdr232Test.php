<?php

namespace Tests\Feature;

use App\Core\Billing\Exceptions\InvalidSubscriptionTransition;
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
 * ADR-232: Subscription State Machine Guard.
 *
 * Tests: allowed transitions, forbidden transitions, invariant violations.
 */
class BillingAdr232Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

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

        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'ADR232 Co',
            'slug' => 'adr232-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
    }

    // ── 1: Allowed transitions succeed ────────────────────

    public function test_pending_payment_to_active(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $sub->update(['status' => 'active', 'is_current' => 1]);
        $this->assertEquals('active', $sub->fresh()->status);
    }

    public function test_pending_payment_to_trialing(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $sub->update(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->addDays(14)]);
        $this->assertEquals('trialing', $sub->fresh()->status);
    }

    public function test_trialing_to_active(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'trialing',
            'is_current' => 1,
            'provider' => 'internal',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $sub->update(['status' => 'active']);
        $this->assertEquals('active', $sub->fresh()->status);
    }

    public function test_active_to_past_due(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->update(['status' => 'past_due']);
        $this->assertEquals('past_due', $sub->fresh()->status);
    }

    public function test_past_due_to_active(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'past_due',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->update(['status' => 'active']);
        $this->assertEquals('active', $sub->fresh()->status);
    }

    public function test_past_due_to_suspended(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'past_due',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->update(['status' => 'suspended', 'is_current' => null]);
        $this->assertEquals('suspended', $sub->fresh()->status);
        $this->assertNull($sub->fresh()->is_current);
    }

    public function test_active_to_cancelled(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->update(['status' => 'cancelled', 'is_current' => null]);
        $this->assertEquals('cancelled', $sub->fresh()->status);
    }

    // ── 2: Forbidden transitions throw ────────────────────

    public function test_cancelled_to_active_forbidden(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'cancelled',
            'provider' => 'internal',
        ]);

        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Invalid subscription transition: cancelled → active');

        $sub->update(['status' => 'active', 'is_current' => 1]);
    }

    public function test_expired_to_active_forbidden(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'expired',
            'provider' => 'internal',
        ]);

        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Invalid subscription transition: expired → active');

        $sub->update(['status' => 'active', 'is_current' => 1]);
    }

    public function test_pending_payment_to_cancelled_forbidden(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Invalid subscription transition: pending_payment → cancelled');

        $sub->update(['status' => 'cancelled']);
    }

    public function test_active_to_trialing_forbidden(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Invalid subscription transition: active → trialing');

        $sub->update(['status' => 'trialing']);
    }

    // ── 3: Invariant violations ───────────────────────────

    public function test_is_current_with_cancelled_status_forbidden(): void
    {
        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Invariant violation: is_current=1 with status=cancelled');

        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'cancelled',
            'is_current' => 1,
            'provider' => 'internal',
        ]);
    }

    public function test_is_current_with_suspended_status_forbidden(): void
    {
        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Invariant violation: is_current=1 with status=suspended');

        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'suspended',
            'is_current' => 1,
            'provider' => 'internal',
        ]);
    }

    public function test_unknown_status_forbidden(): void
    {
        $this->expectException(InvalidSubscriptionTransition::class);
        $this->expectExceptionMessage('Unknown subscription status: bogus');

        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'bogus',
            'provider' => 'internal',
        ]);
    }

    // ── 4: Transition methods ─────────────────────────────

    public function test_mark_active_method(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $sub->markActive();

        $sub->refresh();
        $this->assertEquals('active', $sub->status);
        $this->assertEquals(1, $sub->is_current);
    }

    public function test_mark_past_due_method(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->markPastDue();

        $sub->refresh();
        $this->assertEquals('past_due', $sub->status);
        $this->assertEquals(1, $sub->is_current);
    }

    public function test_mark_suspended_method(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'past_due',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->markSuspended();

        $sub->refresh();
        $this->assertEquals('suspended', $sub->status);
        $this->assertNull($sub->is_current);
    }

    public function test_mark_cancelled_method(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'internal',
        ]);

        $sub->markCancelled();

        $sub->refresh();
        $this->assertEquals('cancelled', $sub->status);
        $this->assertNull($sub->is_current);
    }

    public function test_mark_cancelled_forbidden_from_pending_payment(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $this->expectException(InvalidSubscriptionTransition::class);

        $sub->markCancelled();
    }

    // ── 5: Transition constants ───────────────────────────

    public function test_terminal_states_have_no_transitions(): void
    {
        $this->assertEmpty(Subscription::TRANSITIONS['cancelled']);
        $this->assertEmpty(Subscription::TRANSITIONS['expired']);
    }

    public function test_all_states_covered_in_transitions(): void
    {
        foreach (Subscription::STATES as $state) {
            $this->assertArrayHasKey($state, Subscription::TRANSITIONS, "State '{$state}' missing from TRANSITIONS map");
        }
    }
}
