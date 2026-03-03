<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\NullPaymentGateway;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for plan-level trial support (plans.trial_days).
 *
 * Proves:
 *   1. Plan with trial_days > 0 → subscription created as trialing with trial_ends_at
 *   2. Plan with trial_days = 0 → subscription created as pending (no trial)
 *   3. end_of_trial timing → schedule at trial_ends_at
 *   4. end_of_trial execution → status trialing → active
 */
class PlanTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();
    }

    /**
     * Plan with trial_days=14 → InternalPaymentAdapter creates trialing subscription.
     */
    public function test_plan_with_trial_creates_trialing_subscription(): void
    {
        $plan = Plan::where('key', 'pro')->first();
        $this->assertEquals(14, $plan->trial_days);

        $company = Company::create([
            'name' => 'Trial Co',
            'slug' => 'trial-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $adapter = new InternalPaymentAdapter();
        $result = $adapter->createCheckout($company, 'pro');

        $subscription = Subscription::where('company_id', $company->id)->first();

        $this->assertNotNull($subscription);
        $this->assertEquals('trialing', $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertNotNull($subscription->current_period_end);

        // trial_ends_at is ~14 days from now
        $this->assertTrue(
            $subscription->trial_ends_at->between(now()->addDays(13), now()->addDays(15))
        );
    }

    /**
     * Plan with trial_days=0 → subscription created as pending (no trial).
     */
    public function test_plan_without_trial_creates_pending_subscription(): void
    {
        $plan = Plan::where('key', 'starter')->first();
        $this->assertEquals(0, $plan->trial_days);

        $company = Company::create([
            'name' => 'No Trial Co',
            'slug' => 'no-trial-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $adapter = new InternalPaymentAdapter();
        $adapter->createCheckout($company, 'starter');

        $subscription = Subscription::where('company_id', $company->id)->first();

        $this->assertEquals('pending', $subscription->status);
        $this->assertNull($subscription->trial_ends_at);
    }

    /**
     * NullPaymentGateway also respects trial_days.
     */
    public function test_null_gateway_respects_trial_days(): void
    {
        $company = Company::create([
            'name' => 'Null Trial Co',
            'slug' => 'null-trial-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $gateway = new NullPaymentGateway();
        $gateway->createCheckout($company, 'pro');

        $subscription = Subscription::where('company_id', $company->id)->first();

        $this->assertEquals('trialing', $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
    }

    /**
     * end_of_trial timing on trialing subscription → scheduled at trial_ends_at,
     * then executed → active.
     */
    public function test_end_of_trial_full_lifecycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $company = Company::create([
            'name' => 'Lifecycle Co',
            'slug' => 'lifecycle-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        // Simulate a trialing subscription (as InternalPaymentAdapter would create)
        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'trial_ends_at' => Carbon::parse('2026-03-15'),
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
        ]);

        // Schedule upgrade to business at end_of_trial
        $intent = PlanChangeExecutor::schedule(
            company: $company,
            toPlanKey: 'business',
            timing: 'end_of_trial',
        );

        $this->assertEquals('scheduled', $intent->status);
        $this->assertEquals('2026-03-15', $intent->effective_at->toDateString());

        // Not due yet
        Carbon::setTestNow(Carbon::parse('2026-03-10'));
        $this->assertEquals(0, PlanChangeExecutor::executeScheduled());

        // After trial ends → execute
        Carbon::setTestNow(Carbon::parse('2026-03-15 01:00:00'));
        $this->assertEquals(1, PlanChangeExecutor::executeScheduled());

        $intent->refresh();
        $this->assertEquals('executed', $intent->status);

        $subscription->refresh();
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals('business', $subscription->plan_key);
        $this->assertNull($subscription->trial_ends_at);

        Carbon::setTestNow();
    }

    /**
     * PlanRegistry::definitions() exposes trial_days.
     */
    public function test_plan_registry_exposes_trial_days(): void
    {
        $defs = PlanRegistry::definitions();

        $this->assertArrayHasKey('trial_days', $defs['pro']);
        $this->assertEquals(14, $defs['pro']['trial_days']);
        $this->assertEquals(0, $defs['starter']['trial_days']);
    }
}
