<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-597 (LOT 3) — granular platform permissions: read vs write split for Companies, verified
 * independence between invoice read and billing administration, jobdomains without payments, and
 * removal of dead (declared-but-unenforced) permissions. All enforced server-side (fail-closed).
 */
class PlatformGranularPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
    }

    private function userWith(array $permissionKeys, string $roleKey): PlatformUser
    {
        $role = PlatformRole::create(['key' => $roleKey, 'name' => ucfirst($roleKey)]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $user = PlatformUser::create([
            'first_name' => $roleKey, 'last_name' => 'User',
            'email' => $roleKey . '@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Co', 'slug' => 'co-' . uniqid(), 'market_key' => 'FR', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
    }

    // ── Companies: read without modify ────────────────────────────────────────
    public function test_view_companies_allows_read_but_not_write(): void
    {
        $company = $this->company();
        $reader = $this->userWith(['view_companies'], 'co_reader');

        $this->actingAs($reader, 'platform')->getJson('/api/platform/companies')->assertOk();
        $this->actingAs($reader, 'platform')->getJson("/api/platform/companies/{$company->id}")->assertOk();

        // Writes require manage_companies → forbidden for a read-only role.
        $this->actingAs($reader, 'platform')->putJson("/api/platform/companies/{$company->id}/suspend")->assertForbidden();
        $this->actingAs($reader, 'platform')->putJson("/api/platform/companies/{$company->id}/modules/platform.billing/enable")->assertForbidden();
    }

    public function test_manage_companies_role_can_reach_writes(): void
    {
        $company = $this->company();
        $manager = $this->userWith(['view_companies', 'manage_companies'], 'co_manager');

        $this->actingAs($manager, 'platform')->getJson('/api/platform/companies')->assertOk();
        // Not forbidden (may be 200/422 depending on state) — the point is RBAC lets it through.
        $this->assertNotSame(403, $this->actingAs($manager, 'platform')->putJson("/api/platform/companies/{$company->id}/suspend")->status());
    }

    // ── Invoices: consult without billing administration ──────────────────────
    public function test_view_billing_reads_invoices_but_cannot_administer(): void
    {
        $viewer = $this->userWith(['view_billing'], 'bill_viewer');
        $this->actingAs($viewer, 'platform')->getJson('/api/platform/billing/invoices')->assertOk();
        // Billing administration (config) requires manage_billing → forbidden.
        $this->actingAs($viewer, 'platform')->putJson('/api/platform/billing/config', [])->assertForbidden();
    }

    // ── JobDomains without payments ───────────────────────────────────────────
    public function test_jobdomains_role_has_no_payments_access(): void
    {
        $jd = $this->userWith(['manage_jobdomains'], 'jd_admin');
        $this->actingAs($jd, 'platform')->getJson('/api/platform/jobdomains')->assertOk();
        // No billing/payments permission → billing read AND billing admin both forbidden.
        $this->actingAs($jd, 'platform')->getJson('/api/platform/billing/invoices')->assertForbidden();
        $this->actingAs($jd, 'platform')->putJson('/api/platform/billing/config', [])->assertForbidden();
    }

    // ── One function of a module without the others ───────────────────────────
    public function test_single_function_access_without_siblings(): void
    {
        // Only invoice read — no payments administration, no companies, no jobdomains.
        $u = $this->userWith(['view_billing'], 'one_fn');
        $this->actingAs($u, 'platform')->getJson('/api/platform/billing/invoices')->assertOk();
        $this->actingAs($u, 'platform')->getJson('/api/platform/companies')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/jobdomains')->assertForbidden();
    }

    // ── Dead permissions are gone from the catalog ────────────────────────────
    public function test_dead_permissions_are_removed_from_catalog(): void
    {
        $dead = ['manage_billing_providers', 'manage_billing_policies', 'view_billing_audit',
            'security.view', 'security.manage', 'security.audit.view',
            'realtime.view', 'realtime.manage', 'view_documentation'];

        $this->assertSame(0, PlatformPermission::whereIn('key', $dead)->count(), 'no dead permission remains assignable');
        // The real granular permissions survive.
        $this->assertSame(1, PlatformPermission::where('key', 'view_companies')->count());
        $this->assertSame(1, PlatformPermission::where('key', 'security.alerts.view')->count());
    }
}
