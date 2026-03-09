<?php

namespace Tests\Feature;

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
 *   - GET /api/billing/invoices returns empty stub
 *   - GET /api/billing/subscription returns null when none
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
        $response = $this->getJson('/api/billing/invoices');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════
    // 2) Invoices returns paginated empty list
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
    // 3) Subscription returns null when none
    // ═══════════════════════════════════════════════════════

    public function test_subscription_returns_null_when_none(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/billing/subscription');

        $response->assertOk()
            ->assertExactJson(['subscription' => null, 'pending_subscription' => null]);
    }
}
