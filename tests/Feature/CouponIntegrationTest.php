<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $user = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Integration Co',
            'slug' => 'integration-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        WalletLedger::ensureWallet($this->company);
    }

    public function test_apply_coupon_adds_negative_line(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'SAVE20',
            'name' => '20% off',
            'type' => 'percentage',
            'value' => 2000, // 20% in bps
            'is_active' => true,
        ]);

        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 5000, 1);

        $discount = InvoiceIssuer::applyCoupon($invoice, $coupon, $this->company);

        $this->assertEquals(1000, $discount); // 20% of 5000
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'type' => 'discount',
            'amount' => -1000,
        ]);
    }

    public function test_apply_coupon_records_usage(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'TRACK',
            'name' => 'Tracking test',
            'type' => 'fixed_amount',
            'value' => 2000,
            'is_active' => true,
        ]);

        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 5000, 1);

        InvoiceIssuer::applyCoupon($invoice, $coupon, $this->company);

        $this->assertDatabaseHas('billing_coupon_usages', [
            'coupon_id' => $coupon->id,
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
        ]);

        $this->assertEquals(1, $coupon->fresh()->used_count);
    }

    public function test_subscription_coupon_tracking(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'SUBTRACK',
            'name' => 'Sub tracking',
            'type' => 'percentage',
            'value' => 1000,
            'duration_months' => 3,
            'is_active' => true,
        ]);

        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'coupon_id' => $coupon->id,
            'coupon_months_remaining' => 3,
        ]);

        $this->assertEquals($coupon->id, $sub->coupon_id);
        $this->assertEquals(3, $sub->coupon_months_remaining);
        $this->assertEquals($coupon->id, $sub->coupon->id);
    }

    public function test_finalized_invoice_reflects_coupon_discount(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'FINAL',
            'name' => 'Final test',
            'type' => 'percentage',
            'value' => 5000, // 50%
            'is_active' => true,
        ]);

        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 10000, 1);
        InvoiceIssuer::applyCoupon($invoice, $coupon, $this->company);
        $finalized = InvoiceIssuer::finalize($invoice);

        // Subtotal = 10000 + (-5000) = 5000
        $this->assertEquals(5000, $finalized->subtotal);
    }
}
