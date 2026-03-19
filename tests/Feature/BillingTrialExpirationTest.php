<?php

namespace Tests\Feature;

use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ADR-360: Trial auto-expiration command tests.
 *
 * Proves:
 *   1. Expired trials are transitioned to expired status
 *   2. Active trials are not touched
 *   3. Dry-run mode does not mutate
 *   4. Already expired subscriptions are not re-processed
 *   5. Multiple expired trials processed in one run
 *   6. Trial without trial_ends_at is skipped
 *   7. Non-current (is_current=null) trialing subscriptions are skipped
 *   8. Command returns success exit code
 */
class BillingTrialExpirationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();

        $this->company = Company::create([
            'name' => 'Trial Test Co',
            'slug' => 'trial-test-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);
    }

    private function createSubscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'is_current' => 1,
            'trial_ends_at' => now()->subDay(),
            'current_period_start' => now()->subDays(14),
            'current_period_end' => now()->addMonth(),
        ], $overrides));
    }

    public function test_expires_trial_past_trial_ends_at(): void
    {
        $subscription = $this->createSubscription([
            'trial_ends_at' => now()->subHour(),
        ]);

        Artisan::call('billing:expire-trials');

        $subscription->refresh();
        $this->assertEquals('expired', $subscription->status);
        $this->assertNull($subscription->is_current);
    }

    public function test_does_not_expire_active_trial(): void
    {
        $subscription = $this->createSubscription([
            'trial_ends_at' => now()->addDays(7),
        ]);

        Artisan::call('billing:expire-trials');

        $subscription->refresh();
        $this->assertEquals('trialing', $subscription->status);
        $this->assertEquals(1, $subscription->is_current);
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $subscription = $this->createSubscription([
            'trial_ends_at' => now()->subHour(),
        ]);

        Artisan::call('billing:expire-trials', ['--dry-run' => true]);

        $subscription->refresh();
        $this->assertEquals('trialing', $subscription->status);
        $this->assertEquals(1, $subscription->is_current);
    }

    public function test_already_expired_subscription_not_reprocessed(): void
    {
        $subscription = $this->createSubscription([
            'status' => 'expired',
            'is_current' => null,
            'trial_ends_at' => now()->subDays(10),
        ]);

        Artisan::call('billing:expire-trials');

        $subscription->refresh();
        $this->assertEquals('expired', $subscription->status);
        $this->assertNull($subscription->is_current);
    }

    public function test_multiple_expired_trials_processed(): void
    {
        $company2 = Company::create([
            'name' => 'Trial Test Co 2',
            'slug' => 'trial-test-co-2',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $sub1 = $this->createSubscription(['trial_ends_at' => now()->subHours(2)]);
        $sub2 = Subscription::create([
            'company_id' => $company2->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'is_current' => 1,
            'trial_ends_at' => now()->subHours(5),
            'current_period_start' => now()->subDays(14),
            'current_period_end' => now()->addMonth(),
        ]);

        $exitCode = Artisan::call('billing:expire-trials');

        $sub1->refresh();
        $sub2->refresh();
        $this->assertEquals('expired', $sub1->status);
        $this->assertEquals('expired', $sub2->status);
        $this->assertEquals(0, $exitCode);
    }

    public function test_skips_trial_without_trial_ends_at(): void
    {
        $subscription = $this->createSubscription([
            'trial_ends_at' => null,
        ]);

        Artisan::call('billing:expire-trials');

        $subscription->refresh();
        $this->assertEquals('trialing', $subscription->status);
    }

    public function test_skips_non_current_trialing_subscription(): void
    {
        $subscription = $this->createSubscription([
            'is_current' => null,
            'trial_ends_at' => now()->subHour(),
        ]);

        Artisan::call('billing:expire-trials');

        $subscription->refresh();
        // Still trialing because is_current was null (not the current subscription)
        $this->assertEquals('trialing', $subscription->status);
    }

    public function test_command_returns_success_exit_code(): void
    {
        $exitCode = Artisan::call('billing:expire-trials');

        $this->assertEquals(0, $exitCode);
    }
}
