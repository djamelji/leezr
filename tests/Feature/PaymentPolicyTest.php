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
 * ADR-325: PaymentPolicy facade tests.
 *
 * Tests centralized payment method resolution combining
 * PaymentOrchestrator rules + PlatformBillingPolicy SEPA filters.
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
            isTrial: false,
        );

        $this->assertContains('card', $methods);
    }

    public function test_sepa_allowed_when_policy_allows(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => false,
        ]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
            isTrial: false,
        );

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods);
    }

    public function test_sepa_blocked_when_policy_disallows(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['allow_sepa' => false]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
            isTrial: true,
        );

        $this->assertContains('card', $methods);
        $this->assertNotContains('sepa_debit', $methods);
    }

    public function test_sepa_requires_trial_filters_non_trial(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
            isTrial: false,
        );

        $this->assertContains('card', $methods);
        $this->assertNotContains('sepa_debit', $methods);
    }

    public function test_sepa_requires_trial_allows_trial(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        $methods = PaymentPolicy::allowedMethodsForContext(
            marketKey: 'FR',
            planKey: 'pro',
            interval: 'monthly',
            isTrial: true,
        );

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods);
    }

    public function test_allowed_methods_for_company_with_trial(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        $owner = User::factory()->create();
        $company = Company::create([
            'name' => 'SEPA Test Co',
            'slug' => 'sepa-test-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'trial_ends_at' => now()->addDays(14),
        ]);

        $methods = PaymentPolicy::allowedMethods($company);

        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods);
    }

    public function test_allowed_methods_for_registration(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        // Pro plan has trial_days > 0 in seed data
        $methods = PaymentPolicy::allowedMethodsForRegistration(
            planKey: 'pro',
            interval: 'monthly',
            marketKey: 'FR',
        );

        $this->assertContains('card', $methods);
        // SEPA depends on whether the plan has a trial
    }
}
