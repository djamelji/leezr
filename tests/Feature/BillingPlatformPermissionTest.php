<?php

namespace Tests\Feature;

use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-629 (LOT 7 — permissions Platform Finance). Billing admin endpoints are gated: reads require
 * view_billing, mutations require manage_billing. Verifies the DENIAL direction (security-critical).
 */
class BillingPlatformPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function userWith(array $permissionKeys): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'P', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    public function test_billing_read_denied_without_view_billing(): void
    {
        $user = $this->userWith(['view_dashboard']); // no view_billing
        $this->actingAs($user, 'platform')->getJson('/api/platform/billing/invoices')->assertForbidden();
    }

    public function test_billing_mutation_denied_without_manage_billing(): void
    {
        \App\Core\Markets\MarketRegistry::sync();
        $company = \App\Core\Models\Company::create(['name' => 'Perm Co', 'slug' => 'perm-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
        $inv = \App\Core\Billing\Invoice::create(['company_id' => $company->id, 'number' => 'INV-PERM-1', 'subtotal' => 1000, 'tax_amount' => 200, 'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth()]);

        // Has read but NOT write → voiding a REAL invoice must be refused (manage_billing required).
        $user = $this->userWith(['view_dashboard', 'view_billing']);
        $this->actingAs($user, 'platform')->putJson("/api/platform/billing/invoices/{$inv->id}/void")->assertForbidden();

        // And the invoice is untouched (no financial side effect from a denied request).
        $this->assertSame('open', $inv->fresh()->status);
    }
}
