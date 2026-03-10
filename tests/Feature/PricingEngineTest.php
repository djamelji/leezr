<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\PricingEngine;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\Plan;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-324: PricingEngine unit tests.
 *
 * Tests the centralized pricing engine for current period,
 * plan change, and registration breakdowns.
 */
class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Plan $starterPlan;
    private Plan $proPlan;

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

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'PricingEngine Co',
            'slug' => 'pricing-engine-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        WalletLedger::ensureWallet($this->company);

        $this->starterPlan = Plan::where('key', 'starter')->first();
        $this->proPlan = Plan::where('key', 'pro')->first();
    }

    // ── forCurrentPeriod ─────────────────────────────────

    public function test_for_current_period_without_coupon(): void
    {
        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $breakdown = PricingEngine::forCurrentPeriod($subscription, $this->company);

        $this->assertEquals('EUR', $breakdown->currency);
        $this->assertNotNull($breakdown->planLine());
        $this->assertEquals($this->proPlan->price_monthly, $breakdown->planLine()->unitAmount);
        $this->assertNull($breakdown->coupon);
        $this->assertNull($breakdown->discountLine());
        $this->assertEquals($this->proPlan->price_monthly, $breakdown->subtotal);
    }

    public function test_for_current_period_with_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'TEST20',
            'name' => 'Test 20%',
            'type' => 'percentage',
            'value' => 2000, // 20%
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'coupon_id' => $coupon->id,
            'coupon_months_remaining' => 3,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $breakdown = PricingEngine::forCurrentPeriod($subscription, $this->company);

        $this->assertNotNull($breakdown->coupon);
        $this->assertEquals('TEST20', $breakdown->coupon->code);
        $this->assertNotNull($breakdown->discountLine());

        $expectedDiscount = (int) round($this->proPlan->price_monthly * 2000 / 10000);
        $this->assertEquals(-$expectedDiscount, $breakdown->discountLine()->unitAmount);
        $this->assertEquals($this->proPlan->price_monthly - $expectedDiscount, $breakdown->subtotal);
    }

    public function test_for_current_period_with_addons(): void
    {
        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        // Create addon module if needed
        $module = PlatformModule::firstOrCreate(
            ['key' => 'test_addon_module'],
            ['name' => 'Test Addon', 'scope' => 'company', 'type' => 'addon', 'is_active' => true],
        );

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'test_addon_module',
            'amount_cents' => 1500,
            'interval' => 'monthly',
            'activated_at' => now(),
        ]);

        $breakdown = PricingEngine::forCurrentPeriod($subscription, $this->company);

        $addonLines = $breakdown->addonLines();
        $this->assertCount(1, $addonLines);
        $this->assertEquals(1500, $addonLines[0]->unitAmount);
        $this->assertEquals($this->proPlan->price_monthly + 1500, $breakdown->subtotal);
    }

    // ── forPlanChange ────────────────────────────────────

    public function test_for_plan_change_proration_uses_effective_price(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'PRORATE20',
            'name' => 'Prorate 20%',
            'type' => 'percentage',
            'value' => 2000,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'coupon_id' => $coupon->id,
            'coupon_months_remaining' => 5,
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        $pcb = PricingEngine::forPlanChange($this->company, $subscription, 'business', 'monthly');

        $this->assertTrue($pcb->isUpgrade);
        $this->assertEquals('immediate', $pcb->timing);

        // Proration should use effective price (with coupon) for old plan credit
        $effectiveOld = PricingEngine::effectivePriceCents($subscription);
        $this->assertEquals($pcb->fromPlan['effective_price'], $effectiveOld);
        $this->assertLessThan($pcb->fromPlan['price'], $effectiveOld);
    }

    public function test_for_plan_change_transfers_coupon_to_next_period(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'TRANSFER30',
            'name' => 'Transfer 30%',
            'type' => 'percentage',
            'value' => 3000,
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'coupon_id' => $coupon->id,
            'coupon_months_remaining' => 4,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $pcb = PricingEngine::forPlanChange($this->company, $subscription, 'business', 'monthly');

        // Coupon should be present in the plan change breakdown
        $this->assertNotNull($pcb->activeCoupon);
        $this->assertEquals('TRANSFER30', $pcb->activeCoupon->code);

        // Next period should have a discount line
        $this->assertNotNull($pcb->nextPeriod->discountLine());
    }

    // ── forRegistration ──────────────────────────────────

    public function test_for_registration_with_market_includes_tax(): void
    {
        $market = Market::where('key', 'FR')->first();

        $breakdown = PricingEngine::forRegistration(
            planKey: 'pro',
            interval: 'monthly',
            marketKey: 'FR',
        );

        $this->assertEquals('EUR', $breakdown->currency);
        $this->assertNotNull($breakdown->planLine());

        if ($market->vat_rate_bps > 0 && $breakdown->subtotal > 0) {
            $this->assertGreaterThan(0, $breakdown->taxAmount);
            $expectedTax = (int) floor($breakdown->subtotal * $market->vat_rate_bps / 10000);
            $this->assertEquals($expectedTax, $breakdown->taxAmount);
        }

        $this->assertEquals($breakdown->subtotal + $breakdown->taxAmount, $breakdown->total);
    }

    public function test_for_registration_with_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'WELCOME10',
            'name' => 'Welcome 10%',
            'type' => 'percentage',
            'value' => 1000, // 10%
            'is_active' => true,
        ]);

        $breakdown = PricingEngine::forRegistration(
            planKey: 'pro',
            interval: 'monthly',
            marketKey: 'FR',
            coupon: $coupon,
        );

        $this->assertNotNull($breakdown->coupon);
        $this->assertEquals('WELCOME10', $breakdown->coupon->code);
        $this->assertNotNull($breakdown->discountLine());
    }

    // ── Helpers ──────────────────────────────────────────

    public function test_effective_price_equals_catalog_without_coupon(): void
    {
        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $catalog = PricingEngine::catalogPriceCents($this->proPlan, 'monthly');
        $effective = PricingEngine::effectivePriceCents($subscription);

        $this->assertEquals($catalog, $effective);
        $this->assertEquals($this->proPlan->price_monthly, $catalog);
    }

    public function test_effective_price_is_catalog_minus_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'EFF25',
            'name' => 'Effective 25%',
            'type' => 'percentage',
            'value' => 2500, // 25%
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => 1,
            'provider' => 'stripe',
            'coupon_id' => $coupon->id,
            'coupon_months_remaining' => 2,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $catalog = PricingEngine::catalogPriceCents($this->proPlan, 'monthly');
        $effective = PricingEngine::effectivePriceCents($subscription);

        $expectedDiscount = (int) round($catalog * 2500 / 10000);
        $this->assertEquals($catalog - $expectedDiscount, $effective);
        $this->assertLessThan($catalog, $effective);
    }
}
