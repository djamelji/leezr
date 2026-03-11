<?php

namespace Tests\Feature;

use App\Core\Billing\PaymentPolicy;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-325 + ADR-328: PaymentPolicy facade tests.
 *
 * Tests centralized payment method resolution combining
 * PaymentOrchestrator rules + PlatformBillingPolicy SEPA filters.
 *
 * ADR-328: sepa_requires_trial only applies in registration tunnel,
 * not for existing companies.
 */
class PaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

        Market::firstOrCreate(
            ['key' => 'FR'],
            ['name' => 'France', 'currency' => 'EUR', 'vat_rate_bps' => 2000, 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris', 'dial_code' => '+33'],
        );

        // Seed default rules: card + sepa_debit (wildcard)
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'priority' => 10,
            'is_active' => true,
        ]);

        PlatformPaymentMethodRule::create([
            'method_key' => 'sepa_debit',
            'provider_key' => 'stripe',
            'priority' => 5,
            'is_active' => true,
        ]);
    }

    public function test_card_always_allowed(): void
    {
        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
        );

        $this->assertContains('card', $methods);
    }

    public function test_sepa_allowed_when_policy_allows(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['allow_sepa' => true]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
        );

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods);
    }

    public function test_sepa_blocked_when_master_switch_off(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['allow_sepa' => false]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
        );

        $this->assertContains('card', $methods);
        $this->assertNotContains('sepa_debit', $methods);
    }

    /**
     * ADR-328: sepa_requires_trial does NOT affect allowedMethodsForContext.
     * It only applies in the registration tunnel.
     */
    public function test_sepa_requires_trial_does_not_affect_context(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        // Even with sepa_requires_trial=true, context method returns SEPA
        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
        );

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods, 'sepa_requires_trial should not filter allowedMethodsForContext');
    }

    /**
     * ADR-328: Existing company always gets SEPA if allow_sepa=true,
     * regardless of sepa_requires_trial and subscription status.
     */
    public function test_existing_company_gets_sepa_regardless_of_trial_policy(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        $owner = User::factory()->create();
        $company = Company::create([
            'name' => 'Active Co',
            'slug' => 'active-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        // Active subscription (NOT trialing)
        Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $methods = PaymentPolicy::allowedMethods($company);

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods, 'Active company should see SEPA even with sepa_requires_trial=true');
    }

    /**
     * ADR-328: Registration with trial plan + sepa_requires_trial=true → SEPA allowed.
     */
    public function test_registration_with_trial_plan_gets_sepa(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        // Pro plan has trial_days > 0 in seed data
        $plan = \App\Core\Plans\Plan::where('key', 'pro')->first();

        if (! $plan || $plan->trial_days <= 0) {
            $this->markTestSkipped('Pro plan does not have trial days configured');
        }

        $methods = PaymentPolicy::allowedMethodsForRegistration(
            planKey: 'pro',
            interval: 'monthly',
            marketKey: 'FR',
        );

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods);
    }

    /**
     * ADR-328: Registration with no-trial plan + sepa_requires_trial=true → SEPA blocked.
     */
    public function test_registration_without_trial_plan_blocks_sepa(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        // Starter plan has trial_days = 0
        $plan = \App\Core\Plans\Plan::where('key', 'starter')->first();

        if (! $plan || $plan->trial_days > 0) {
            $this->markTestSkipped('Starter plan has trial days, cannot test non-trial SEPA block');
        }

        $methods = PaymentPolicy::allowedMethodsForRegistration(
            planKey: 'starter',
            interval: 'monthly',
            marketKey: 'FR',
        );

        $this->assertContains('card', $methods);
        $this->assertNotContains('sepa_debit', $methods, 'SEPA should be blocked for non-trial plans during registration');
    }

    /**
     * ADR-328: Registration with sepa_requires_trial=false → SEPA always allowed.
     */
    public function test_registration_sepa_always_when_policy_off(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => false,
        ]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForRegistration(
            planKey: 'starter',
            interval: 'monthly',
            marketKey: 'FR',
        );

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods, 'SEPA should be available when sepa_requires_trial=false');
    }
}
