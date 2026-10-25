<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-631f (LOT 7C) — the coupon "delete" action has TWO real outcomes the UI now surfaces truthfully:
 * a coupon WITH usages is DEACTIVATED (redemption history preserved), an UNUSED coupon is hard-deleted.
 * This pins the backend behaviour the dialog relies on.
 */
class CouponDeleteBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function admin(): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['view_billing', 'manage_billing'])->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'A', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    private function coupon(int $usedCount): BillingCoupon
    {
        return BillingCoupon::create([
            'code' => 'C'.strtoupper(substr(uniqid(), -6)), 'name' => 'C', 'type' => 'percentage', 'value' => 10,
            'used_count' => $usedCount, 'is_active' => true,
        ]);
    }

    public function test_used_coupon_is_deactivated_not_deleted(): void
    {
        $c = $this->coupon(3);

        $this->actingAs($this->admin(), 'platform')
            ->deleteJson("/api/platform/billing/coupons/{$c->id}")
            ->assertOk();

        $fresh = BillingCoupon::find($c->id);
        $this->assertNotNull($fresh, 'used coupon must NOT be hard-deleted');
        $this->assertFalse($fresh->is_active, 'used coupon must be deactivated');
        $this->assertSame(3, $fresh->used_count, 'redemption history preserved');
    }

    public function test_unused_coupon_is_hard_deleted(): void
    {
        $c = $this->coupon(0);

        $this->actingAs($this->admin(), 'platform')
            ->deleteJson("/api/platform/billing/coupons/{$c->id}")
            ->assertOk();

        $this->assertNull(BillingCoupon::find($c->id), 'unused coupon is hard-deleted');
    }
}
