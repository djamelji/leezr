<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\BillingCouponUsage;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Modules\Core\Billing\Services\CouponService;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-316: Coupon system — validation, application, CRUD, public endpoint.
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private CouponService $couponService;
    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Coupon Co',
            'slug' => 'coupon-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        $this->couponService = new CouponService();

        // Platform admin for CRUD tests
        $this->admin = PlatformUser::create([
            'first_name' => 'Coupon',
            'last_name' => 'Admin',
            'email' => 'coupon-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ═══════════════════════════════════════════════════════
    // 1) Validation — CouponService::validate()
    // ═══════════════════════════════════════════════════════

    public function test_validate_valid_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'WELCOME20',
            'name' => 'Welcome 20%',
            'type' => 'percentage',
            'value' => 2000,
            'is_active' => true,
        ]);

        $result = $this->couponService->validate('WELCOME20', $this->company, 'starter', subtotalCents: 10000);

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['coupon']);
        $this->assertNull($result['error']);
        $this->assertEquals(2000, $result['discount_preview']);
    }

    public function test_validate_expired_coupon(): void
    {
        BillingCoupon::create([
            'code' => 'EXPIRED10',
            'name' => 'Expired coupon',
            'type' => 'percentage',
            'value' => 1000,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $result = $this->couponService->validate('EXPIRED10', $this->company, 'starter');

        $this->assertFalse($result['valid']);
        $this->assertEquals('coupon_expired', $result['error']);
    }

    public function test_validate_exhausted_coupon(): void
    {
        BillingCoupon::create([
            'code' => 'EXHAUSTED',
            'name' => 'Exhausted coupon',
            'type' => 'fixed_amount',
            'value' => 500,
            'max_uses' => 3,
            'used_count' => 3,
            'is_active' => true,
        ]);

        $result = $this->couponService->validate('EXHAUSTED', $this->company, 'starter');

        $this->assertFalse($result['valid']);
        $this->assertEquals('coupon_exhausted', $result['error']);
    }

    public function test_validate_inactive_coupon(): void
    {
        BillingCoupon::create([
            'code' => 'INACTIVE',
            'name' => 'Inactive coupon',
            'type' => 'percentage',
            'value' => 1000,
            'is_active' => false,
        ]);

        $result = $this->couponService->validate('INACTIVE', $this->company, 'starter');

        $this->assertFalse($result['valid']);
        $this->assertEquals('coupon_inactive', $result['error']);
    }

    public function test_validate_wrong_plan(): void
    {
        BillingCoupon::create([
            'code' => 'PROONLY',
            'name' => 'Pro only coupon',
            'type' => 'percentage',
            'value' => 1500,
            'applicable_plan_keys' => ['pro'],
            'is_active' => true,
        ]);

        $result = $this->couponService->validate('PROONLY', $this->company, 'starter');

        $this->assertFalse($result['valid']);
        $this->assertEquals('coupon_not_applicable', $result['error']);
    }

    public function test_validate_already_used(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'ONCEONLY',
            'name' => 'Once-only coupon',
            'type' => 'fixed_amount',
            'value' => 1000,
            'is_active' => true,
        ]);

        BillingCouponUsage::create([
            'coupon_id' => $coupon->id,
            'company_id' => $this->company->id,
            'applied_at' => now(),
            'discount_amount' => 1000,
        ]);

        $result = $this->couponService->validate('ONCEONLY', $this->company, 'starter');

        $this->assertFalse($result['valid']);
        $this->assertEquals('coupon_usage_limit_per_company', $result['error']);
    }

    // ═══════════════════════════════════════════════════════
    // 2) Application — CouponService::apply() / calculateDiscount()
    // ═══════════════════════════════════════════════════════

    public function test_apply_percentage_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'PCT20',
            'name' => '20% off',
            'type' => 'percentage',
            'value' => 2000, // 20% in basis points
            'is_active' => true,
        ]);

        $discount = $this->couponService->calculateDiscount($coupon, 10000);

        $this->assertEquals(2000, $discount);
    }

    public function test_apply_fixed_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'FIXED15',
            'name' => '15 EUR off',
            'type' => 'fixed_amount',
            'value' => 1500,
            'is_active' => true,
        ]);

        $discount = $this->couponService->calculateDiscount($coupon, 10000);

        $this->assertEquals(1500, $discount);
    }

    public function test_apply_fixed_coupon_caps_at_subtotal(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'BIGFIXED',
            'name' => '200 EUR off',
            'type' => 'fixed_amount',
            'value' => 20000,
            'is_active' => true,
        ]);

        $discount = $this->couponService->calculateDiscount($coupon, 10000);

        $this->assertEquals(10000, $discount);
    }

    public function test_coupon_usage_recorded(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'TRACK',
            'name' => 'Tracked coupon',
            'type' => 'fixed_amount',
            'value' => 500,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
        ]);

        $invoice = InvoiceIssuer::createDraft($this->company, $sub->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Starter plan', 2900, 1);
        $invoice = InvoiceIssuer::finalize($invoice);

        $discount = $this->couponService->apply($coupon, $invoice, $this->company);

        $this->assertEquals(500, $discount);

        $this->assertDatabaseHas('billing_coupon_usages', [
            'coupon_id' => $coupon->id,
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'discount_amount' => 500,
        ]);

        $coupon->refresh();
        $this->assertEquals(1, $coupon->used_count);
    }

    // ═══════════════════════════════════════════════════════
    // 3) Platform CRUD — CouponCrudController
    // ═══════════════════════════════════════════════════════

    public function test_platform_crud_index(): void
    {
        BillingCoupon::create([
            'code' => 'LIST1',
            'name' => 'First',
            'type' => 'percentage',
            'value' => 1000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/billing/coupons');

        $response->assertOk()
            ->assertJsonCount(1, 'coupons')
            ->assertJsonPath('coupons.0.code', 'LIST1');
    }

    public function test_platform_crud_store(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/billing/coupons', [
                'code' => 'new25',
                'name' => 'New 25%',
                'type' => 'percentage',
                'value' => 2500,
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('coupon.code', 'NEW25')
            ->assertJsonPath('coupon.type', 'percentage')
            ->assertJsonPath('coupon.value', 2500);

        $this->assertDatabaseHas('billing_coupons', ['code' => 'NEW25']);
    }

    public function test_platform_crud_update(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'UPD',
            'name' => 'Update me',
            'type' => 'fixed_amount',
            'value' => 500,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/billing/coupons/{$coupon->id}", [
                'name' => 'Updated name',
                'value' => 1000,
            ]);

        $response->assertOk()
            ->assertJsonPath('coupon.name', 'Updated name')
            ->assertJsonPath('coupon.value', 1000);
    }

    public function test_platform_crud_delete_unused(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'DEL',
            'name' => 'Delete me',
            'type' => 'fixed_amount',
            'value' => 500,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->deleteJson("/api/platform/billing/coupons/{$coupon->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('billing_coupons', ['id' => $coupon->id]);
    }

    public function test_platform_crud_delete_used_deactivates(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'USED',
            'name' => 'Used coupon',
            'type' => 'fixed_amount',
            'value' => 500,
            'used_count' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->deleteJson("/api/platform/billing/coupons/{$coupon->id}");

        $response->assertOk();

        $coupon->refresh();
        $this->assertFalse($coupon->is_active);
        $this->assertDatabaseHas('billing_coupons', ['id' => $coupon->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 4) Public validation endpoint
    // ═══════════════════════════════════════════════════════

    public function test_public_validate_coupon_valid(): void
    {
        BillingCoupon::create([
            'code' => 'PUBLIC20',
            'name' => 'Public 20%',
            'type' => 'percentage',
            'value' => 2000,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/public/validate-coupon', [
            'code' => 'public20',
            'plan_key' => 'pro',
            'subtotal_cents' => 5000,
        ]);

        $response->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('error', null)
            ->assertJsonPath('discount_preview', 1000)
            ->assertJsonPath('discount_type', 'percentage')
            ->assertJsonPath('discount_value', 2000);
    }

    public function test_public_validate_coupon_not_found(): void
    {
        $response = $this->postJson('/api/public/validate-coupon', [
            'code' => 'NONEXISTENT',
            'plan_key' => 'pro',
        ]);

        $response->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('error', 'coupon_not_found');
    }

    public function test_public_validate_coupon_requires_code(): void
    {
        $response = $this->postJson('/api/public/validate-coupon', [
            'plan_key' => 'pro',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
