<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\BillingCouponUsage;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Modules\Core\Billing\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $couponService;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->couponService = new CouponService();

        $user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Enrichment Co',
            'slug' => 'enrichment-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_billing_cycle_mismatch(): void
    {
        BillingCoupon::create([
            'code' => 'ANNUAL50',
            'name' => 'Annual only',
            'type' => 'percentage',
            'value' => 5000,
            'applicable_billing_cycles' => ['yearly'],
            'is_active' => true,
        ]);

        $result = $this->couponService->validate('ANNUAL50', $this->company, 'pro', 'monthly');
        $this->assertFalse($result['valid']);
        $this->assertEquals('coupon_billing_cycle_mismatch', $result['error']);

        $result2 = $this->couponService->validate('ANNUAL50', $this->company, 'pro', 'yearly');
        $this->assertTrue($result2['valid']);
    }

    public function test_first_purchase_only(): void
    {
        BillingCoupon::create([
            'code' => 'NEWCO',
            'name' => 'New company',
            'type' => 'percentage',
            'value' => 2000,
            'first_purchase_only' => true,
            'is_active' => true,
        ]);

        // No subscription → should work
        $result = $this->couponService->validate('NEWCO', $this->company, 'pro');
        $this->assertTrue($result['valid']);

        // Create a subscription
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $result2 = $this->couponService->validate('NEWCO', $this->company, 'pro');
        $this->assertFalse($result2['valid']);
        $this->assertEquals('coupon_first_purchase_only', $result2['error']);
    }

    public function test_max_uses_per_company(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'MULTI3',
            'name' => 'Multi use',
            'type' => 'fixed_amount',
            'value' => 500,
            'max_uses_per_company' => 3,
            'is_active' => true,
        ]);

        // Use it twice
        for ($i = 0; $i < 2; $i++) {
            BillingCouponUsage::create([
                'coupon_id' => $coupon->id,
                'company_id' => $this->company->id,
                'applied_at' => now(),
                'discount_amount' => 500,
            ]);
        }

        // 2 uses < 3 max → still valid
        $result = $this->couponService->validate('MULTI3', $this->company, 'pro');
        $this->assertTrue($result['valid']);

        // 3rd usage
        BillingCouponUsage::create([
            'coupon_id' => $coupon->id,
            'company_id' => $this->company->id,
            'applied_at' => now(),
            'discount_amount' => 500,
        ]);

        // 3 uses >= 3 max → blocked
        $result2 = $this->couponService->validate('MULTI3', $this->company, 'pro');
        $this->assertFalse($result2['valid']);
        $this->assertEquals('coupon_usage_limit_per_company', $result2['error']);
    }

    public function test_new_fields_stored_correctly(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'FULL',
            'name' => 'Full featured',
            'description' => 'Test description',
            'type' => 'percentage',
            'value' => 1500,
            'max_uses_per_company' => 5,
            'applicable_billing_cycles' => ['monthly', 'yearly'],
            'applicable_addon_keys' => ['crm', 'analytics'],
            'addon_mode' => 'include',
            'duration_months' => 6,
            'first_purchase_only' => true,
            'is_active' => true,
        ]);

        $fresh = $coupon->fresh();
        $this->assertEquals('Test description', $fresh->description);
        $this->assertEquals(5, $fresh->max_uses_per_company);
        $this->assertEquals(['monthly', 'yearly'], $fresh->applicable_billing_cycles);
        $this->assertEquals(['crm', 'analytics'], $fresh->applicable_addon_keys);
        $this->assertEquals('include', $fresh->addon_mode);
        $this->assertEquals(6, $fresh->duration_months);
        $this->assertTrue($fresh->first_purchase_only);
    }
}
