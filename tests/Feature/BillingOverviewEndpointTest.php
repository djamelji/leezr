<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-236: Billing overview endpoint tests.
 */
class BillingOverviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        ModuleRegistry::sync();
        PlanRegistry::sync();

        PlatformPaymentModule::firstOrCreate(
            ['provider_key' => 'stripe'],
            [
                'name' => 'Stripe',
                'is_installed' => true,
                'is_active' => true,
                'health_status' => 'healthy',
            ],
        );

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Overview Test Co',
            'slug' => 'overview-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    // ── 1: Overview returns all expected keys ──────

    public function test_overview_returns_expected_structure(): void
    {
        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertArrayHasKey('subscription', $overview);
        $this->assertArrayHasKey('plan', $overview);
        $this->assertArrayHasKey('addons', $overview);
        $this->assertArrayHasKey('trial', $overview);
        $this->assertArrayHasKey('wallet_balance', $overview);
        $this->assertArrayHasKey('outstanding_invoices', $overview);
        $this->assertArrayHasKey('outstanding_amount', $overview);
        $this->assertArrayHasKey('currency', $overview);
        $this->assertArrayHasKey('payment_method', $overview);
    }

    // ── 2: Overview with no subscription ──────────

    public function test_overview_with_no_subscription(): void
    {
        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertNull($overview['subscription']);
        $this->assertNull($overview['plan']);
        $this->assertEmpty($overview['addons']);
        $this->assertNull($overview['trial']);
        $this->assertNull($overview['payment_method']);
        $this->assertEquals(0, $overview['outstanding_invoices']);
    }

    // ── 3: Overview with active subscription ──────

    public function test_overview_with_active_subscription(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertNotNull($overview['subscription']);
        $this->assertEquals('pro', $overview['subscription']['plan_key']);
        $this->assertEquals('active', $overview['subscription']['status']);
        $this->assertNotNull($overview['plan']);
        $this->assertEquals('pro', $overview['plan']['key']);
    }

    // ── 4: Overview includes addons ────────────────

    public function test_overview_includes_active_addons(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);

        $wallet = WalletLedger::ensureWallet($this->company);

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'test_addon',
            'interval' => 'monthly',
            'amount_cents' => 999,
            'currency' => $wallet->currency,
            'activated_at' => now(),
        ]);

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertCount(1, $overview['addons']);
        $this->assertEquals('test_addon', $overview['addons'][0]['module_key']);
        $this->assertEquals(999, $overview['addons'][0]['amount_cents']);
    }

    // ── 5: Overview trial info ─────────────────────

    public function test_overview_trial_info(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'stripe',
            'is_current' => 1,
            'trial_ends_at' => now()->addDays(7),
        ]);

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertNotNull($overview['trial']);
        $this->assertGreaterThan(0, $overview['trial']['days_remaining']);
        $this->assertLessThanOrEqual(7, $overview['trial']['days_remaining']);
    }

    // ── 6: Overview payment method ─────────────────

    public function test_overview_payment_method(): void
    {
        CompanyPaymentProfile::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'method_key' => 'card',
            'provider_payment_method_id' => 'pm_test',
            'is_default' => true,
            'label' => 'Visa ending 4242',
            'metadata' => [
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2027,
            ],
        ]);

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertNotNull($overview['payment_method']);
        $this->assertEquals('visa', $overview['payment_method']['brand']);
        $this->assertEquals('4242', $overview['payment_method']['last4']);
    }

    // ── 7: Overview outstanding invoices ────────────

    public function test_overview_outstanding_invoices(): void
    {
        Invoice::create([
            'company_id' => $this->company->id,
            'number' => 'INV-001',
            'status' => 'overdue',
            'amount' => 2900,
            'subtotal' => 2900,
            'amount_due' => 2900,
            'currency' => 'EUR',
            'finalized_at' => now(),
            'issued_at' => now()->subDays(30),
            'due_at' => now()->subDays(15),
        ]);

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertEquals(1, $overview['outstanding_invoices']);
        $this->assertEquals(2900, $overview['outstanding_amount']);
    }

    // ── 8: Overview currency from market ───────────

    public function test_overview_currency_from_market(): void
    {
        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($this->company);

        $this->assertEquals('EUR', $overview['currency']);
    }
}
