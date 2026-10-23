<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Models\Company;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-595 (LOT 1) — closes the A-01 hole: the Dashboard endpoints are permission-gated,
 * fail-closed, and enforced server-side independently of the menu. Global search leaks no
 * resource the caller cannot otherwise see.
 */
class PlatformDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
    }

    private function userWithPermissions(array $permissionKeys, string $roleKey = 'restricted'): PlatformUser
    {
        $role = PlatformRole::create(['key' => $roleKey, 'name' => ucfirst($roleKey)]);
        if ($permissionKeys !== []) {
            $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        }
        $user = PlatformUser::create([
            'first_name' => $roleKey, 'last_name' => 'User',
            'email' => $roleKey . '@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function superAdmin(): PlatformUser
    {
        $user = PlatformUser::create([
            'first_name' => 'Root', 'last_name' => 'Admin',
            'email' => 'root@test.com', 'password' => 'P@ssw0rd!Strong',
        ]);
        $user->roles()->attach(PlatformRole::where('key', 'super_admin')->first()->id);

        return $user;
    }

    // ── Super Admin: full access ──────────────────────────────────────────────
    public function test_super_admin_reaches_all_dashboard_endpoints(): void
    {
        $u = $this->superAdmin();
        $this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/layout')->assertOk();
        $this->actingAs($u, 'platform')->getJson('/api/platform/system/health')->assertOk();
        $this->actingAs($u, 'platform')->getJson('/api/platform/usage/overview')->assertOk();
    }

    // ── Platform sans permission: fully locked out (fail-closed) ──────────────
    public function test_user_without_permissions_is_denied_everywhere(): void
    {
        $u = $this->userWithPermissions([], 'nobody');
        $this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/layout')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/system/health')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/usage/overview')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/search?q=acme')->assertForbidden();
    }

    // ── Platform Finance: dashboard yes, supervision no ───────────────────────
    public function test_finance_role_has_dashboard_but_not_supervision(): void
    {
        $u = $this->userWithPermissions(['view_dashboard', 'view_billing', 'manage_billing', 'manage_plans'], 'finance');
        $this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/layout')->assertOk();
        $this->actingAs($u, 'platform')->getJson('/api/platform/system/health')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/usage/overview')->assertForbidden();
    }

    // ── Platform Support: dashboard yes, supervision no ───────────────────────
    public function test_support_role_has_dashboard_but_not_supervision(): void
    {
        $u = $this->userWithPermissions(['view_dashboard', 'manage_support'], 'support');
        $this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/layout')->assertOk();
        $this->actingAs($u, 'platform')->getJson('/api/platform/system/health')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/usage/overview')->assertForbidden();
    }

    // ── Direct API access to a sensitive endpoint is enforced server-side ─────
    public function test_direct_api_access_to_usage_is_enforced_without_relying_on_menu(): void
    {
        // A finance user calling the usage API directly (no menu involved) → 403.
        $u = $this->userWithPermissions(['view_dashboard', 'view_billing'], 'finance2');
        $this->actingAs($u, 'platform')->getJson('/api/platform/usage/company/1')->assertForbidden();
    }

    // ── Permission revocation flips access on the next request ────────────────
    public function test_revoking_a_permission_flips_access(): void
    {
        $role = PlatformRole::create(['key' => 'ops', 'name' => 'Ops']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['view_dashboard', 'view_system_health'])->pluck('id')->all());
        $user = PlatformUser::create(['first_name' => 'Ops', 'last_name' => 'U', 'email' => 'ops@test.com', 'password' => 'P@ssw0rd!Strong']);
        $user->roles()->attach($role->id);

        $this->actingAs($user, 'platform')->getJson('/api/platform/system/health')->assertOk();

        // Revoke → next request is denied (effective permissions resolved per request).
        $role->permissions()->sync(PlatformPermission::where('key', 'view_dashboard')->pluck('id')->all());
        $this->actingAs($user, 'platform')->getJson('/api/platform/system/health')->assertForbidden();
    }

    // ── Global search never leaks a resource the caller cannot see ────────────
    public function test_global_search_filters_categories_by_permission(): void
    {
        $company = Company::create(['name' => 'Acme Corp', 'slug' => 'acme-corp', 'market_key' => 'FR', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
        $member = \App\Core\Models\User::create(['first_name' => 'Acme', 'last_name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'P@ssw0rd!Strong']);
        Invoice::create(['company_id' => $company->id, 'number' => 'INV-ACME-1', 'amount' => 1000, 'subtotal' => 1000, 'amount_due' => 0, 'currency' => 'EUR', 'status' => 'paid']);
        SupportTicket::create(['company_id' => $company->id, 'created_by_user_id' => $member->id, 'subject' => 'Acme ticket', 'status' => 'open', 'priority' => 'normal', 'category' => 'other']);

        // Finance (view_billing, no support, no companies): sees Invoices, not Companies/Support.
        $finance = $this->userWithPermissions(['view_dashboard', 'view_billing'], 'finance3');
        $res = $this->actingAs($finance, 'platform')->getJson('/api/platform/search?q=acme')->assertOk();
        $titles = collect($res->json())->pluck('title');
        $this->assertTrue($titles->contains('Invoices'), 'finance sees invoices');
        $this->assertFalse($titles->contains('Support Tickets'), 'finance must NOT see support tickets');
        $this->assertFalse($titles->contains('Companies'), 'finance must NOT see companies');

        // Support (manage_support, no billing): sees Support, not Invoices.
        $support = $this->userWithPermissions(['view_dashboard', 'manage_support'], 'support3');
        $res2 = $this->actingAs($support, 'platform')->getJson('/api/platform/search?q=acme')->assertOk();
        $titles2 = collect($res2->json())->pluck('title');
        $this->assertTrue($titles2->contains('Support Tickets'), 'support sees tickets');
        $this->assertFalse($titles2->contains('Invoices'), 'support must NOT see invoices');

        // Super admin sees everything.
        $res3 = $this->actingAs($this->superAdmin(), 'platform')->getJson('/api/platform/search?q=acme')->assertOk();
        $titles3 = collect($res3->json())->pluck('title');
        $this->assertTrue($titles3->contains('Invoices') && $titles3->contains('Support Tickets') && $titles3->contains('Companies'), 'super admin sees all categories');
    }
}
