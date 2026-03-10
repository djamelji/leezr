<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-323: Company Billing Detail endpoint (platform admin support tools).
 *
 * Covers:
 *   - Enriched billing response includes provider_customer_id + provider_links
 *   - Subscription includes eager-loaded coupon
 *   - Last payment is returned
 *   - Invoices include paid_at field
 *   - Unauthenticated access blocked
 *   - Response structure is complete (provider-agnostic)
 */
class CompanyBillingDetailTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $admin;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->admin = PlatformUser::create([
            'first_name' => 'Support',
            'last_name' => 'Admin',
            'email' => 'support-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        $this->company = Company::create([
            'name' => 'Detail Test Co',
            'slug' => 'detail-test-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);
    }

    private function act()
    {
        return $this->actingAs($this->admin, 'platform');
    }

    // ═══════════════════════════════════════════════════════
    // 1) Unauthenticated access blocked
    // ═══════════════════════════════════════════════════════

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson("/api/platform/companies/{$this->company->id}/billing")
            ->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════
    // 2) Response includes provider_customer_id
    // ═══════════════════════════════════════════════════════

    public function test_billing_includes_provider_customer_id(): void
    {
        CompanyPaymentCustomer::create([
            'company_id' => $this->company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test123',
        ]);

        $response = $this->act()
            ->getJson("/api/platform/companies/{$this->company->id}/billing");

        $response->assertOk()
            ->assertJsonPath('provider_customer_id', 'cus_test123');
    }

    // ═══════════════════════════════════════════════════════
    // 3) Subscription includes eager-loaded coupon
    // ═══════════════════════════════════════════════════════

    public function test_subscription_includes_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'WELCOME20',
            'name' => 'Welcome discount',
            'type' => 'percentage',
            'value' => 20,
            'is_active' => true,
        ]);

        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => true,
            'coupon_id' => $coupon->id,
            'coupon_months_remaining' => 3,
        ]);

        $response = $this->act()
            ->getJson("/api/platform/companies/{$this->company->id}/billing");

        $response->assertOk()
            ->assertJsonPath('subscription.coupon.code', 'WELCOME20')
            ->assertJsonPath('subscription.coupon.type', 'percentage')
            ->assertJsonPath('subscription.coupon.value', 20);
    }

    // ═══════════════════════════════════════════════════════
    // 4) Last payment is returned
    // ═══════════════════════════════════════════════════════

    public function test_billing_includes_last_payment(): void
    {
        Payment::create([
            'company_id' => $this->company->id,
            'amount' => 4900,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_test456',
        ]);

        $response = $this->act()
            ->getJson("/api/platform/companies/{$this->company->id}/billing");

        $response->assertOk()
            ->assertJsonPath('last_payment.amount', 4900)
            ->assertJsonPath('last_payment.status', 'succeeded')
            ->assertJsonPath('last_payment.provider_payment_id', 'pi_test456');
    }

    // ═══════════════════════════════════════════════════════
    // 5) Invoices include paid_at field
    // ═══════════════════════════════════════════════════════

    public function test_invoices_include_paid_at(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'is_current' => true,
        ]);

        Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $sub->id,
            'number' => 'INV-TEST-001',
            'amount' => 4900,
            'subtotal' => 4900,
            'amount_due' => 0,
            'currency' => 'EUR',
            'status' => 'paid',
            'issued_at' => now(),
            'paid_at' => now()->subDay(),
        ]);

        $response = $this->act()
            ->getJson("/api/platform/companies/{$this->company->id}/billing");

        $response->assertOk();

        $invoices = $response->json('invoices');
        $this->assertNotEmpty($invoices);
        $this->assertNotNull($invoices[0]['paid_at']);
    }

    // ═══════════════════════════════════════════════════════
    // 6) Response structure includes provider_links (provider-agnostic)
    // ═══════════════════════════════════════════════════════

    public function test_billing_response_has_complete_structure(): void
    {
        $response = $this->act()
            ->getJson("/api/platform/companies/{$this->company->id}/billing");

        $response->assertOk()
            ->assertJsonStructure([
                'subscription',
                'invoices',
                'payment_methods',
                'wallet_balance',
                'currency',
                'dunning_invoices',
                'provider_customer_id',
                'provider_links' => [
                    'customer_url',
                    'subscription_url',
                    'payment_url',
                ],
                'last_payment',
            ]);
    }
}
