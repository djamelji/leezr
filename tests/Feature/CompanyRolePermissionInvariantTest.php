<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * Invariant: operational roles (is_administrative=false) cannot
 * hold admin permissions (company_permissions.is_admin=true).
 *
 * Tests the CompanyRoleController::update() hardening that strips
 * admin permissions on management→operational transition, even when
 * the permissions array is absent from the payload.
 */
class CompanyRolePermissionInvariantTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Inv Co', 'slug' => 'inv-co']);
        $this->activateCompanyModules($this->company);
        $this->setUpCompanyRbac($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ───────────────────────────────────────────────────────────
    // management→operational strips admin perms without payload
    // ───────────────────────────────────────────────────────────

    public function test_management_to_operational_strips_admin_permissions_even_without_permissions_payload(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'test_mgmt',
            'name' => 'Test Mgmt',
            'is_administrative' => true,
        ]);

        // Give it both admin and non-admin permissions
        $adminPermIds = CompanyPermission::where('is_admin', true)->pluck('id')->toArray();
        $nonAdminPermIds = CompanyPermission::where('is_admin', false)->take(2)->pluck('id')->toArray();
        $role->permissions()->sync(array_merge($adminPermIds, $nonAdminPermIds));

        // Transition to operational WITHOUT sending permissions
        $response = $this->actAs($this->owner)
            ->putJson("/api/company/roles/{$role->id}", [
                'is_administrative' => false,
            ]);

        $response->assertOk();

        // DB pivot must no longer contain any is_admin=true permissions
        $remaining = $role->fresh()->permissions;
        $adminRemaining = $remaining->filter(fn ($p) => $p->is_admin);

        $this->assertEmpty($adminRemaining->toArray(),
            'Transitioning management→operational must strip all admin permissions from pivot');
    }

    public function test_management_to_operational_keeps_non_admin_permissions_without_permissions_payload(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'test_mgmt2',
            'name' => 'Test Mgmt 2',
            'is_administrative' => true,
        ]);

        $nonAdminPerms = CompanyPermission::where('is_admin', false)->take(3)->pluck('id')->toArray();
        $adminPerms = CompanyPermission::where('is_admin', true)->take(1)->pluck('id')->toArray();
        $role->permissions()->sync(array_merge($nonAdminPerms, $adminPerms));

        $response = $this->actAs($this->owner)
            ->putJson("/api/company/roles/{$role->id}", [
                'is_administrative' => false,
            ]);

        $response->assertOk();

        $remaining = $role->fresh()->permissions->pluck('id')->sort()->values()->toArray();
        sort($nonAdminPerms);

        $this->assertEquals($nonAdminPerms, $remaining,
            'Non-admin permissions must be preserved after management→operational transition');
    }

    public function test_operational_update_without_permissions_does_not_change_permissions_if_no_transition(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'test_op',
            'name' => 'Test Op',
            'is_administrative' => false,
        ]);

        $nonAdminPerms = CompanyPermission::where('is_admin', false)->take(2)->pluck('id')->toArray();
        $role->permissions()->sync($nonAdminPerms);

        // Update name only — no is_administrative change, no permissions array
        $response = $this->actAs($this->owner)
            ->putJson("/api/company/roles/{$role->id}", [
                'name' => 'Renamed Op',
            ]);

        $response->assertOk();

        $remaining = $role->fresh()->permissions->pluck('id')->sort()->values()->toArray();
        sort($nonAdminPerms);

        $this->assertEquals($nonAdminPerms, $remaining,
            'Permissions must not change when updating name only without transition');
    }

    // ───────────────────────────────────────────────────────────
    // Existing guard still works with permissions in payload
    // ───────────────────────────────────────────────────────────

    public function test_operational_role_rejects_admin_permissions_via_payload(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'test_op2',
            'name' => 'Test Op 2',
            'is_administrative' => false,
        ]);

        $adminPermIds = CompanyPermission::where('is_admin', true)->pluck('id')->toArray();

        $response = $this->actAs($this->owner)
            ->putJson("/api/company/roles/{$role->id}", [
                'permissions' => $adminPermIds,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);
    }

    // ───────────────────────────────────────────────────────────
    // Permission catalog scope — no platform modules
    // ───────────────────────────────────────────────────────────

    public function test_permission_catalog_excludes_platform_modules(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/company/permissions');

        $response->assertOk();

        $moduleKeys = collect($response->json('modules'))->pluck('module_key')->toArray();

        foreach ($moduleKeys as $key) {
            $this->assertStringStartsNotWith('platform.', $key,
                "Catalog must not contain platform module: {$key}");
            $this->assertStringStartsNotWith('payments.', $key,
                "Catalog must not contain payments module: {$key}");
        }

        // Must contain known company modules
        $this->assertContains('core.members', $moduleKeys);
        $this->assertContains('core.settings', $moduleKeys);
    }

    public function test_permission_catalog_has_no_empty_permission_ids_bundles(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/company/permissions');

        $response->assertOk();

        $modules = $response->json('modules');

        foreach ($modules as $module) {
            foreach ($module['capabilities'] as $bundle) {
                $this->assertNotEmpty($bundle['permission_ids'],
                    "Bundle '{$bundle['key']}' in module '{$module['module_key']}' has empty permission_ids");
            }
        }
    }
}
