<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\NullPaymentGateway;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-238: admin_approval_required policy wiring tests.
 *
 * Verifies that NullPaymentGateway and InternalPaymentAdapter
 * respect PlatformBillingPolicy.admin_approval_required.
 */
class BillingAdminApprovalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Approval Test Co',
            'slug' => 'approval-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
    }

    // ── 1: Default policy has admin_approval_required = false ──

    public function test_default_policy_has_admin_approval_false(): void
    {
        $policy = PlatformBillingPolicy::instance();

        $this->assertFalse($policy->admin_approval_required);
    }

    // ── 2: Null driver + approval=false → active subscription ──

    public function test_null_driver_auto_activates_when_approval_not_required(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => false]);

        // Use pro plan with trial_days=0 for this test
        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'monthly');

        $this->assertNotNull($result->subscriptionId);

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals(1, $subscription->is_current);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertNotNull($subscription->current_period_end);

        // Company plan synced
        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);
    }

    // ── 3: Null driver + approval=true → pending subscription ──

    public function test_null_driver_creates_pending_when_approval_required(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => true]);

        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'monthly');

        $this->assertNotNull($result->subscriptionId);

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('pending', $subscription->status);
        $this->assertNull($subscription->is_current);

        // Company plan NOT synced
        $this->company->refresh();
        $this->assertEquals('starter', $this->company->plan_key);
    }

    // ── 4: Internal driver + approval=false → active ──

    public function test_internal_driver_auto_activates_when_approval_not_required(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => false]);

        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        $adapter = new InternalPaymentAdapter();
        $result = $adapter->createCheckout($this->company, 'pro', 'monthly');

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals(1, $subscription->is_current);

        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);
    }

    // ── 5: Internal driver + approval=true → pending ──

    public function test_internal_driver_creates_pending_when_approval_required(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => true]);

        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        $adapter = new InternalPaymentAdapter();
        $result = $adapter->createCheckout($this->company, 'pro', 'monthly');

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('pending', $subscription->status);
        $this->assertNull($subscription->is_current);

        $this->company->refresh();
        $this->assertEquals('starter', $this->company->plan_key);
    }

    // ── 6: Trial plan always creates trialing regardless of policy ──

    public function test_trial_plan_creates_trialing_regardless_of_approval_policy(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => true]);

        // Pro plan has trial_days=14 from seeder — use it
        $plan = Plan::where('key', 'pro')->first();
        $this->assertGreaterThan(0, $plan->trial_days, 'Pro plan should have trial_days > 0');

        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'monthly');

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('trialing', $subscription->status);
        $this->assertEquals(1, $subscription->is_current);

        $this->company->refresh();
        $this->assertEquals('pro', $this->company->plan_key);
    }

    // ── 7: Auto mode deactivates previous subscription ──

    public function test_auto_mode_deactivates_previous_subscription(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => false]);

        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        // Create existing active subscription
        $oldSub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'null',
            'is_current' => 1,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addMonth(),
        ]);

        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'monthly');

        $newSub = Subscription::find($result->subscriptionId);
        $this->assertEquals('active', $newSub->status);
        $this->assertEquals(1, $newSub->is_current);

        $oldSub->refresh();
        $this->assertNull($oldSub->is_current);
    }

    // ── 8: Pending mode blocks duplicate pending ──

    public function test_pending_mode_blocks_duplicate_pending(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => true]);

        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        $gateway = new NullPaymentGateway();
        $result1 = $gateway->createCheckout($this->company, 'pro', 'monthly');
        $this->assertNotNull($result1->subscriptionId);

        // Second attempt should be rejected
        $result2 = $gateway->createCheckout($this->company, 'pro', 'monthly');
        $this->assertNull($result2->subscriptionId);
        $this->assertStringContainsString('pending', $result2->message);
    }

    // ── 9: Yearly interval sets correct period ──

    public function test_auto_mode_yearly_sets_correct_period(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => false]);

        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'yearly');

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals('yearly', $subscription->interval);

        $expectedEnd = now()->addDays(365);
        $this->assertTrue(
            $subscription->current_period_end->diffInDays($expectedEnd) <= 1,
            'Yearly subscription should have ~365 day period'
        );
    }

    // ── 10: Policy is read from PlatformBillingPolicy, not legacy JSON ──

    public function test_policy_read_from_platform_billing_policy_not_json(): void
    {
        Plan::where('key', 'pro')->update(['trial_days' => 0]);

        // Set JSON legacy to true (old system)
        $settings = \App\Platform\Models\PlatformSetting::instance();
        $billing = $settings->billing ?? [];
        $billing['policies'] = ['admin_approval_required' => true];
        $settings->update(['billing' => $billing]);

        // Set real policy to false
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['admin_approval_required' => false]);

        // Real policy should win — subscription should be active
        $gateway = new NullPaymentGateway();
        $result = $gateway->createCheckout($this->company, 'pro', 'monthly');

        $subscription = Subscription::find($result->subscriptionId);
        $this->assertEquals('active', $subscription->status);
    }
}
