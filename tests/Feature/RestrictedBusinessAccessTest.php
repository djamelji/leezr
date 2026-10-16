<?php

namespace Tests\Feature;

use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * Restricted-access gate (product decision, audit 2026-10).
 *
 * On trial expiration without a valid subscription, a company enters restricted
 * access: it keeps READ access + subscription/payment, but can no longer perform
 * business operations. The decision is taken at request time (scheduler-independent)
 * and reactivation is automatic + idempotent. Company suspension is NOT the same
 * thing as subscription expiration.
 */
class RestrictedBusinessAccessTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Trial Co',
            'slug' => 'trial-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->activateCompanyModules($this->company);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
    }

    private function sub(array $attrs): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'provider' => 'internal',
        ], $attrs));
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ── Pure decision logic ──────────────────────────────────

    public function test_active_subscription_grants_business_access(): void
    {
        $this->sub(['status' => 'active', 'is_current' => 1]);
        $this->assertTrue($this->company->fresh()->hasBusinessAccess());
    }

    public function test_live_trial_grants_business_access(): void
    {
        $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->addDays(5)]);
        $this->assertTrue($this->company->fresh()->hasBusinessAccess());
    }

    public function test_expired_trial_still_flagged_current_is_restricted_without_scheduler(): void
    {
        // Scheduler never ran: status still 'trialing', is_current=1, but window elapsed.
        $s = $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->subDay()]);

        $this->assertTrue($s->isExpiredTrial());
        $this->assertTrue($s->restrictsBusinessAccess());
        $this->assertFalse($this->company->fresh()->hasBusinessAccess());
    }

    public function test_flipped_expired_subscription_is_restricted(): void
    {
        $this->sub(['status' => 'expired', 'is_current' => null, 'trial_ends_at' => now()->subDays(10)]);
        $this->assertFalse($this->company->fresh()->hasBusinessAccess());
    }

    public function test_cancelled_subscription_is_restricted(): void
    {
        $this->sub(['status' => 'cancelled', 'is_current' => null]);
        $this->assertFalse($this->company->fresh()->hasBusinessAccess());
    }

    public function test_company_without_subscription_is_not_restricted(): void
    {
        // Onboarding / implicit free — this gate must not lock them out.
        $this->assertTrue($this->company->fresh()->hasBusinessAccess());
    }

    public function test_past_due_keeps_access_dunning_handles_it(): void
    {
        $this->sub(['status' => 'past_due', 'is_current' => 1]);
        $this->assertTrue($this->company->fresh()->hasBusinessAccess());
    }

    public function test_reactivation_is_automatic_and_idempotent(): void
    {
        $expired = $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->subDay()]);
        $this->assertFalse($this->company->fresh()->hasBusinessAccess());

        // Regularization: a valid subscription now exists.
        $expired->update(['status' => 'expired', 'is_current' => null]);
        $this->sub([
            'status' => 'active',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        // No extra step needed; access restored purely from state.
        $this->assertTrue($this->company->fresh()->hasBusinessAccess());
        // Idempotent: re-evaluating yields the same answer.
        $this->assertTrue($this->company->fresh()->hasBusinessAccess());
    }

    public function test_subscription_expiration_is_not_company_suspension(): void
    {
        $this->sub(['status' => 'expired', 'is_current' => null]);
        $company = $this->company->fresh();

        $this->assertFalse($company->hasBusinessAccess()); // restricted…
        $this->assertFalse($company->isSuspended());        // …but NOT suspended
    }

    // ── HTTP gate integration ────────────────────────────────

    public function test_restricted_company_cannot_perform_business_mutation(): void
    {
        $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->subDay()]);

        $this->asOwner()
            ->putJson('/api/company', ['name' => 'Renamed'])
            ->assertStatus(402)
            ->assertJson(['code' => 'subscription_required', 'restricted_access' => true]);
    }

    public function test_restricted_company_can_still_read(): void
    {
        $this->sub(['status' => 'expired', 'is_current' => null]);

        // A GET must never be gated — data stays visible.
        $this->asOwner()->getJson('/api/billing/overview')->assertStatus(200);
    }

    public function test_restricted_company_can_still_reach_billing_mutations(): void
    {
        $this->sub(['status' => 'expired', 'is_current' => null]);

        // Billing write is on the allowlist → the gate must not return 402
        // (it may fail validation/business rules, but never the access gate).
        $response = $this->asOwner()->postJson('/api/billing/setup-intent', []);
        $this->assertNotSame(402, $response->getStatusCode());
    }

    public function test_active_company_business_mutation_is_not_gated(): void
    {
        $this->sub([
            'status' => 'active',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $response = $this->asOwner()->putJson('/api/company', ['name' => 'Renamed']);
        $this->assertNotSame(402, $response->getStatusCode());
    }
}
