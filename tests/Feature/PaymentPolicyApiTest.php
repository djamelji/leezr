<?php

namespace Tests\Feature;

use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-325: PaymentPolicy API integration tests.
 */
class PaymentPolicyApiTest extends TestCase
{
    use RefreshDatabase;

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

        Market::firstOrCreate(
            ['key' => 'FR'],
            ['name' => 'France', 'currency' => 'EUR', 'vat_rate_bps' => 2000, 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris', 'dial_code' => '+33'],
        );

        // Seed default rules
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

    public function test_estimate_registration_includes_allowed_methods(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => true,
        ]);
        PlatformBillingPolicy::clearCache();

        $response = $this->postJson('/api/public/plans/estimate-registration', [
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'market_key' => 'FR',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['allowed_payment_methods']);

        $methods = $response->json('allowed_payment_methods');
        $this->assertIsArray($methods);
        $this->assertContains('card', $methods);
    }

    public function test_billing_overview_includes_allowed_methods(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'allow_sepa' => true,
            'sepa_requires_trial' => false,
        ]);
        PlatformBillingPolicy::clearCache();

        $owner = User::factory()->create();
        $company = Company::create([
            'name' => 'API Test Co',
            'slug' => 'api-test-co',
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
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        WalletLedger::ensureWallet($company);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->getJson('/api/billing/overview');

        $response->assertOk();
        $response->assertJsonStructure(['allowed_payment_methods']);

        $methods = $response->json('allowed_payment_methods');
        $this->assertIsArray($methods);
        $this->assertContains('card', $methods);
        $this->assertContains('sepa_debit', $methods);
    }
}
