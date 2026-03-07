<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-237: Next invoice preview endpoint tests.
 */
class BillingNextInvoicePreviewTest extends TestCase
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
            'name' => 'Preview Test Co',
            'slug' => 'preview-test-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    // ── 1: Preview returns null when no subscription ──────

    public function test_preview_returns_null_without_subscription(): void
    {
        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);

        $this->assertNull($preview);
    }

    // ── 2: Preview with plan only ─────────────────────────

    public function test_preview_with_plan_only(): void
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

        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);

        $this->assertNotNull($preview);
        $this->assertArrayHasKey('plan', $preview);
        $this->assertArrayHasKey('addons', $preview);
        $this->assertArrayHasKey('total', $preview);
        $this->assertArrayHasKey('currency', $preview);
        $this->assertArrayHasKey('next_billing_date', $preview);

        $this->assertNotNull($preview['plan']);
        $this->assertEquals('monthly', $preview['plan']['interval']);
        $this->assertEmpty($preview['addons']);

        $plan = Plan::where('key', 'pro')->first();
        $this->assertEquals($plan->price_monthly, $preview['plan']['price']);

        // Total includes 20% tax (FR market = 2000 bps, exclusive mode)
        $expectedTax = (int) floor($plan->price_monthly * 2000 / 10000);
        $this->assertEquals($plan->price_monthly + $expectedTax, $preview['total']);
    }

    // ── 3: Preview with plan + addons ─────────────────────

    public function test_preview_with_plan_and_addons(): void
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

        $wallet = WalletLedger::ensureWallet($this->company);

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'interval' => 'monthly',
            'amount_cents' => 500,
            'currency' => $wallet->currency,
            'activated_at' => now(),
        ]);

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_fleet',
            'interval' => 'monthly',
            'amount_cents' => 300,
            'currency' => $wallet->currency,
            'activated_at' => now(),
        ]);

        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);

        $this->assertCount(2, $preview['addons']);

        $plan = Plan::where('key', 'pro')->first();
        $subtotal = $plan->price_monthly + 500 + 300;
        $expectedTax = (int) floor($subtotal * 2000 / 10000);
        $this->assertEquals($subtotal + $expectedTax, $preview['total']);

        // Each addon should have a name
        foreach ($preview['addons'] as $addon) {
            $this->assertArrayHasKey('name', $addon);
            $this->assertArrayHasKey('price', $addon);
            $this->assertArrayHasKey('module_key', $addon);
        }
    }

    // ── 4: Preview with trial subscription ────────────────

    public function test_preview_with_trial_subscription(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'stripe',
            'is_current' => 1,
            'trial_ends_at' => now()->addDays(10),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(10),
        ]);

        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);

        $this->assertNotNull($preview);
        $this->assertNotNull($preview['trial_remaining_days']);
        $this->assertGreaterThan(0, $preview['trial_remaining_days']);
        $this->assertLessThanOrEqual(10, $preview['trial_remaining_days']);
    }

    // ── 5: Preview currency comes from market ─────────────

    public function test_preview_currency_from_market(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);

        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);

        $this->assertNotNull($preview);
        $this->assertEquals('EUR', $preview['currency']);
    }

    // ── 6: Preview yearly interval uses yearly price ──────

    public function test_preview_yearly_uses_yearly_price(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'yearly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addYear(),
        ]);

        $preview = CompanyBillingReadService::nextInvoicePreview($this->company);

        $plan = Plan::where('key', 'pro')->first();
        $this->assertEquals($plan->price_yearly, $preview['plan']['price']);
        $this->assertEquals('yearly', $preview['plan']['interval']);
    }

    // ── 7: API endpoint returns preview in wrapper ────────

    public function test_api_endpoint_returns_preview(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/next-invoice-preview');

        $response->assertOk();
        $response->assertJsonStructure([
            'preview' => ['currency', 'plan', 'addons', 'total'],
        ]);
    }

    // ── 8: API endpoint returns null preview without sub ──

    public function test_api_endpoint_returns_null_without_subscription(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/next-invoice-preview');

        $response->assertOk();
        $response->assertJson(['preview' => null]);
    }
}
