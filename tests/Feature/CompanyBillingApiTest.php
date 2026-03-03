<?php

namespace Tests\Feature;

use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * Company Billing API — feature tests.
 *
 * Covers:
 *   - Unauthenticated access blocked (401)
 *   - GET /api/billing/payment-methods returns resolved methods
 *   - GET /api/billing/invoices returns empty stub
 *   - GET /api/billing/payments returns empty stub
 *   - GET /api/billing/subscription returns null when none
 *   - GET /api/billing/portal-url returns null
 */
class CompanyBillingApiTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Billing Co',
            'slug' => 'billing-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Activate all company-scope modules (including core.billing)
        $this->activateCompanyModules($this->company);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 1) Unauthenticated access blocked
    // ═══════════════════════════════════════════════════════

    public function test_unauthenticated_blocked(): void
    {
        $response = $this->getJson('/api/billing/payment-methods');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════
    // 2) Payment methods returns resolved methods
    // ═══════════════════════════════════════════════════════

    public function test_payment_methods_returns_resolved_methods(): void
    {
        // Create internal module + rule so the orchestrator can resolve 'manual'
        PlatformPaymentModule::create([
            'provider_key' => 'internal',
            'name' => 'Internal',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        PlatformPaymentMethodRule::create([
            'method_key' => 'manual',
            'provider_key' => 'internal',
            'priority' => 0,
            'is_active' => true,
        ]);

        $response = $this->actAs($this->owner)
            ->getJson('/api/billing/payment-methods');

        $response->assertOk()
            ->assertJsonStructure(['methods'])
            ->assertJsonFragment(['method_key' => 'manual']);
    }

    // ═══════════════════════════════════════════════════════
    // 3) Invoices returns paginated empty list
    // ═══════════════════════════════════════════════════════

    public function test_invoices_returns_paginated_empty(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/billing/invoices');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);
    }

    // ═══════════════════════════════════════════════════════
    // 4) Payments returns paginated empty list
    // ═══════════════════════════════════════════════════════

    public function test_payments_returns_paginated_empty(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/billing/payments');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);
    }

    // ═══════════════════════════════════════════════════════
    // 5) Subscription returns null when none
    // ═══════════════════════════════════════════════════════

    public function test_subscription_returns_null_when_none(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/billing/subscription');

        $response->assertOk()
            ->assertExactJson(['subscription' => null]);
    }

    // ═══════════════════════════════════════════════════════
    // 6) Portal URL returns null
    // ═══════════════════════════════════════════════════════

    public function test_portal_url_returns_null(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/billing/portal-url');

        $response->assertOk()
            ->assertExactJson(['url' => null]);
    }
}
