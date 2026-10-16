<?php

namespace Tests\Feature;

use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Root cause of the "next invoice in the past" anomaly (audit 13): billing:renew only
 * advances subscriptions with is_current=1. An active subscription left with
 * is_current=NULL (seeder bug) is silently skipped forever → its period never moves →
 * the UI shows a past "next invoice" date. This guards that behaviour explicitly.
 */
class SubscriptionRenewalEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Plans\PlanRegistry::sync();
    }

    private function company(string $slug): Company
    {
        return Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
    }

    private function elapsedActiveSub(Company $c, ?int $isCurrent, string $plan = 'pro'): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => $plan, 'interval' => 'monthly',
            'status' => 'active', 'provider' => 'internal', 'is_current' => $isCurrent,
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDay(),
        ]);
    }

    public function test_active_sub_without_is_current_is_skipped_by_renewal(): void
    {
        $sub = $this->elapsedActiveSub($this->company('frozen-co'), null, 'starter');

        Artisan::call('billing:renew');

        // Period NOT advanced — the exact freeze that produces a past "next invoice".
        $this->assertTrue($sub->fresh()->current_period_end->isPast(),
            'An active is_current=NULL subscription is skipped by renewal (period stays frozen).');
    }

    public function test_active_sub_with_is_current_is_advanced_by_renewal(): void
    {
        // Free plan renews immediately (no payment needed) → deterministic period advance.
        $sub = $this->elapsedActiveSub($this->company('healthy-co'), 1, 'starter');

        Artisan::call('billing:renew');

        // With is_current=1 the period is carried forward to the future.
        $this->assertTrue($sub->fresh()->current_period_end->isFuture(),
            'An active is_current=1 subscription must be renewed to a future period.');
    }
}
