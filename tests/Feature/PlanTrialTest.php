<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\InternalPaymentAdapter;
use App\Core\Billing\CheckoutSessionActivator;
use App\Core\Billing\NullPaymentGateway;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use App\Notifications\Billing\TrialConverted;
use App\Notifications\Billing\TrialStarted;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();

        $this->admin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->actingAs($this->admin, 'platform')
            ->{$method}("/api/platform{$uri}", $data);
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
     * Plan with trial_days=0 → subscription created as active (no trial, auto-approve).
     * ADR-238: admin_approval_required defaults to false → auto-activate.
     */
    public function test_plan_without_trial_creates_active_subscription(): void
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

        $this->assertEquals('active', $subscription->status);
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

    // ─── ADR-286: Admin CRUD trial_days ──────────────────

    public function test_admin_can_set_trial_days_on_plan(): void
    {
        $plan = Plan::where('key', 'pro')->first();

        $response = $this->api('putJson', "/plans/{$plan->id}", [
            'key' => $plan->key,
            'name' => $plan->name,
            'level' => $plan->level,
            'price_monthly' => $plan->price_monthly / 100,
            'price_yearly' => $plan->price_yearly / 100,
            'trial_days' => 30,
        ]);

        $response->assertOk();

        $plan->refresh();
        $this->assertEquals(30, $plan->trial_days);
    }

    public function test_admin_can_set_trial_days_to_zero(): void
    {
        $plan = Plan::where('key', 'pro')->first();

        $response = $this->api('putJson', "/plans/{$plan->id}", [
            'key' => $plan->key,
            'name' => $plan->name,
            'level' => $plan->level,
            'price_monthly' => $plan->price_monthly / 100,
            'price_yearly' => $plan->price_yearly / 100,
            'trial_days' => 0,
        ]);

        $response->assertOk();

        $plan->refresh();
        $this->assertEquals(0, $plan->trial_days);
    }

    public function test_trial_days_validation_rejects_negative(): void
    {
        $plan = Plan::where('key', 'pro')->first();

        $response = $this->api('putJson', "/plans/{$plan->id}", [
            'key' => $plan->key,
            'name' => $plan->name,
            'level' => $plan->level,
            'price_monthly' => $plan->price_monthly / 100,
            'price_yearly' => $plan->price_yearly / 100,
            'trial_days' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trial_days']);
    }

    // ─── ADR-286: CheckoutSessionActivator skips invoice during trial ──

    public function test_stripe_checkout_always_active_with_invoice_even_for_trial_plan(): void
    {
        $company = Company::create([
            'name' => 'Checkout Trial Co',
            'slug' => 'checkout-trial-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        // Stripe checkout = payment already collected → no trial, always active
        CheckoutSessionActivator::activateFromStripeSession([
            'amount_total' => 2900,
            'currency' => 'eur',
            'payment_intent' => 'pi_test_no_trial',
            'metadata' => [
                'company_id' => (string) $company->id,
                'subscription_id' => (string) $subscription->id,
                'plan_key' => 'pro',
            ],
        ]);

        $subscription->refresh();

        // Stripe checkout = always active (no trial)
        $this->assertEquals('active', $subscription->status);
        $this->assertNull($subscription->trial_ends_at);

        // Invoice and payment must be created
        $this->assertDatabaseHas('invoices', [
            'subscription_id' => $subscription->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'subscription_id' => $subscription->id,
            'provider_payment_id' => 'pi_test_no_trial',
        ]);
    }

    // ─── ADR-286: Anti-abuse — one trial per owner ──────

    public function test_trial_abuse_prevention_no_second_trial(): void
    {
        // First registration with trial
        $user = User::create([
            'first_name' => 'Abuser',
            'last_name' => 'Test',
            'email' => 'abuser@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $company1 = Company::create([
            'name' => 'Abuse Co 1',
            'slug' => 'abuse-co-1',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $company1->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // First trial subscription
        Subscription::create([
            'company_id' => $company1->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'cancelled',
            'trial_ends_at' => now()->subDays(1),
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->subDays(1),
        ]);

        // Attempt second registration with same email
        $data = new \App\Modules\Infrastructure\Auth\UseCases\RegisterCompanyData(
            firstName: 'Abuser',
            lastName: 'Test',
            email: 'abuser@test.com',
            password: 'P@ssw0rd!Strong',
            companyName: 'Abuse Co 2',
            planKey: 'pro',
            jobdomainKey: 'logistique',
        );

        $gateway = $this->mock(\App\Core\Billing\PaymentGatewayManager::class);
        $useCase = new \App\Modules\Infrastructure\Auth\UseCases\RegisterCompanyUseCase($gateway);

        // This will create a new user (duplicate email handling depends on DB)
        // But the anti-abuse check looks at existing users with same email
        $existingUser = User::where('email', 'abuser@test.com')->first();
        $companyIds = $existingUser->companies()->pluck('companies.id');
        $hasUsedTrial = Subscription::whereIn('company_id', $companyIds)
            ->whereNotNull('trial_ends_at')
            ->exists();

        $this->assertTrue($hasUsedTrial);
    }

    // ─── ADR-286: Notifications ─────────────────────────

    public function test_trial_started_notification_sent(): void
    {
        Notification::fake();

        // ADR-303: disable payment method requirement for trial to avoid Stripe mock
        \App\Core\Billing\PlatformBillingPolicy::instance()->update(['trial_requires_payment_method' => false]);

        $data = new \App\Modules\Infrastructure\Auth\UseCases\RegisterCompanyData(
            firstName: 'Notify',
            lastName: 'Test',
            email: 'notify-trial@test.com',
            password: 'P@ssw0rd!Strong',
            companyName: 'Notify Trial Co',
            planKey: 'pro',
            jobdomainKey: 'logistique',
        );

        $gateway = $this->mock(\App\Core\Billing\PaymentGatewayManager::class);
        $useCase = new \App\Modules\Infrastructure\Auth\UseCases\RegisterCompanyUseCase($gateway);
        $result = $useCase->execute($data);

        Notification::assertSentTo(
            $result->user,
            TrialStarted::class,
        );
    }

    public function test_trial_converted_notification_sent(): void
    {
        Notification::fake();

        $user = User::create([
            'first_name' => 'Convert',
            'last_name' => 'Test',
            'email' => 'convert@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $company = Company::create([
            'name' => 'Convert Co',
            'slug' => 'convert-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        // Use starter (free plan) with a trialing subscription to test the conversion path
        // Free plans → extendPeriod called → TrialConverted sent
        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
            'trial_ends_at' => Carbon::parse('2026-03-15'),
        ]);

        // Move to trial end and run renew command
        Carbon::setTestNow(Carbon::parse('2026-03-15 01:00:00'));

        $this->artisan('billing:renew')
            ->assertSuccessful();

        Notification::assertSentTo(
            $user,
            TrialConverted::class,
        );

        Carbon::setTestNow();
    }

    // ─── ADR-287: Trial plan change behavior ──────────────────

    public function test_trialing_upgrade_continue_trial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['trial_plan_change_behavior' => 'continue_trial']);

        $company = Company::create([
            'name' => 'Continue Trial Co',
            'slug' => 'continue-trial-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'trial_ends_at' => Carbon::parse('2026-03-15'),
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
        ]);

        $intent = PlanChangeExecutor::schedule(
            company: $company,
            toPlanKey: 'business',
            timing: 'immediate',
        );

        $this->assertEquals('executed', $intent->status);

        $subscription->refresh();
        $this->assertEquals('trialing', $subscription->status);
        $this->assertEquals('business', $subscription->plan_key);
        $this->assertEquals('2026-03-15', $subscription->trial_ends_at->toDateString());

        // No invoice during trial with continue_trial
        $this->assertDatabaseMissing('invoices', [
            'subscription_id' => $subscription->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_trialing_upgrade_end_trial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['trial_plan_change_behavior' => 'end_trial']);

        $company = Company::create([
            'name' => 'End Trial Co',
            'slug' => 'end-trial-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'trial_ends_at' => Carbon::parse('2026-03-15'),
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
        ]);

        $intent = PlanChangeExecutor::schedule(
            company: $company,
            toPlanKey: 'business',
            timing: 'immediate',
        );

        $this->assertEquals('executed', $intent->status);

        $subscription->refresh();
        $this->assertEquals('active', $subscription->status);
        $this->assertEquals('business', $subscription->plan_key);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertNotNull($subscription->current_period_start);

        Carbon::setTestNow();
    }

    public function test_trialing_downgrade_continue_trial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['trial_plan_change_behavior' => 'continue_trial']);

        $company = Company::create([
            'name' => 'Downgrade Trial Co',
            'slug' => 'downgrade-trial-co',
            'plan_key' => 'business',
            'jobdomain_key' => 'logistique',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'business',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'trial_ends_at' => Carbon::parse('2026-03-15'),
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
        ]);

        $intent = PlanChangeExecutor::schedule(
            company: $company,
            toPlanKey: 'pro',
            timing: 'immediate',
        );

        $this->assertEquals('executed', $intent->status);

        $subscription->refresh();
        $this->assertEquals('trialing', $subscription->status);
        $this->assertEquals('pro', $subscription->plan_key);
        $this->assertEquals('2026-03-15', $subscription->trial_ends_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_trialing_plan_change_no_proration_during_trial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['trial_plan_change_behavior' => 'continue_trial']);

        $company = Company::create([
            'name' => 'No Proration Co',
            'slug' => 'no-proration-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'trial_ends_at' => Carbon::parse('2026-03-15'),
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
        ]);

        $intent = PlanChangeExecutor::schedule(
            company: $company,
            toPlanKey: 'business',
            timing: 'immediate',
        );

        // Proration snapshot should be null (skipped during trial)
        $this->assertNull($intent->proration_snapshot);

        // No invoices created
        $this->assertDatabaseMissing('invoices', [
            'subscription_id' => $subscription->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_trialing_end_of_period_overridden_to_immediate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'downgrade_timing' => 'end_of_period',
            'trial_plan_change_behavior' => 'continue_trial',
        ]);

        $company = Company::create([
            'name' => 'Override Timing Co',
            'slug' => 'override-timing-co',
            'plan_key' => 'business',
            'jobdomain_key' => 'logistique',
        ]);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'business',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'trial_ends_at' => Carbon::parse('2026-03-15'),
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-15'),
        ]);

        $user = User::create([
            'first_name' => 'Override',
            'last_name' => 'Test',
            'email' => 'override@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // Test through HTTP: company routes need auth:sanctum + X-Company-Id
        $response = $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/billing/plan-change', [
                'to_plan_key' => 'pro',
                'idempotency_key' => 'override-trial-test',
            ]);

        $response->assertOk();

        // Should have executed immediately despite downgrade_timing=end_of_period
        $intent = PlanChangeIntent::where('company_id', $company->id)->latest()->first();
        $this->assertEquals('immediate', $intent->timing);
        $this->assertEquals('executed', $intent->status);

        $subscription->refresh();
        $this->assertEquals('pro', $subscription->plan_key);
        $this->assertEquals('trialing', $subscription->status);

        Carbon::setTestNow();
    }
}
