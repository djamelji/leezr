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
 * Tests for core.roles module — permission-based API access.
 *
 * Validates:
 *   - 403 on roles API without roles.view / roles.manage
 *   - Owner bypass intact
 *   - Management without roles.view cannot see Roles nav item
 *   - Permission catalog includes roles.* permissions
 */
class CompanyRolesModuleTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $manager;
    private User $limitedAdmin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Roles Co', 'slug' => 'roles-co']);
        $this->activateCompanyModules($this->company);
        $this->setUpCompanyRbac($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Manager: management role WITH roles.view + roles.manage
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'full_mgr',
            'name' => 'Full Manager',
            'is_administrative' => true,
        ]);

        $allPerms = CompanyPermission::pluck('id')->toArray();
        $managerRole->permissions()->sync($allPerms);

        $this->manager = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->manager->id,
            'role' => 'user',
            'company_role_id' => $managerRole->id,
        ]);

        // Limited admin: management but WITHOUT roles.*
        $limitedRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'limited_mgr',
            'name' => 'Limited Manager',
            'is_administrative' => true,
        ]);

        $nonRolesPerms = CompanyPermission::where('key', 'not like', 'roles.%')
            ->pluck('id')->toArray();
        $limitedRole->permissions()->sync($nonRolesPerms);

        $this->limitedAdmin = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->limitedAdmin->id,
            'role' => 'user',
            'company_role_id' => $limitedRole->id,
        ]);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ───────────────────────────────────────────────────────────
    // GET /company/roles — requires roles.view
    // ───────────────────────────────────────────────────────────

    public function test_get_roles_returns_403_without_roles_view(): void
    {
        $this->actAs($this->limitedAdmin)
            ->getJson('/api/company/roles')
            ->assertForbidden();
    }

    public function test_get_roles_succeeds_with_roles_view(): void
    {
        $this->actAs($this->manager)
            ->getJson('/api/company/roles')
            ->assertOk()
            ->assertJsonStructure(['roles']);
    }

    public function test_get_roles_owner_bypass(): void
    {
        $this->actAs($this->owner)
            ->getJson('/api/company/roles')
            ->assertOk();
    }

    // ───────────────────────────────────────────────────────────
    // GET /company/permissions — requires roles.view
    // ───────────────────────────────────────────────────────────

    public function test_get_permission_catalog_returns_403_without_roles_view(): void
    {
        $this->actAs($this->limitedAdmin)
            ->getJson('/api/company/permissions')
            ->assertForbidden();
    }

    public function test_get_permission_catalog_succeeds_with_roles_view(): void
    {
        $this->actAs($this->manager)
            ->getJson('/api/company/permissions')
            ->assertOk()
            ->assertJsonStructure(['permissions', 'modules']);
    }

    // ───────────────────────────────────────────────────────────
    // POST /company/roles — requires roles.manage
    // ───────────────────────────────────────────────────────────

    public function test_create_role_returns_403_without_roles_manage(): void
    {
        $this->actAs($this->limitedAdmin)
            ->postJson('/api/company/roles', ['name' => 'Test'])
            ->assertForbidden();
    }

    public function test_create_role_succeeds_with_roles_manage(): void
    {
        $this->actAs($this->manager)
            ->postJson('/api/company/roles', ['name' => 'New Role'])
            ->assertCreated();
    }

    // ───────────────────────────────────────────────────────────
    // PUT /company/roles/{id} — requires roles.manage
    // ───────────────────────────────────────────────────────────

    public function test_update_role_returns_403_without_roles_manage(): void
    {
        $role = CompanyRole::where('company_id', $this->company->id)
            ->where('key', 'full_mgr')->first();

        $this->actAs($this->limitedAdmin)
            ->putJson("/api/company/roles/{$role->id}", ['name' => 'Renamed'])
            ->assertForbidden();
    }

    // ───────────────────────────────────────────────────────────
    // DELETE /company/roles/{id} — requires roles.manage
    // ───────────────────────────────────────────────────────────

    public function test_delete_role_returns_403_without_roles_manage(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'deletable',
            'name' => 'Deletable',
            'is_administrative' => false,
        ]);

        $this->actAs($this->limitedAdmin)
            ->deleteJson("/api/company/roles/{$role->id}")
            ->assertForbidden();
    }

    // ───────────────────────────────────────────────────────────
    // Nav: management without roles.view → no Roles item
    // ───────────────────────────────────────────────────────────

    public function test_nav_hides_roles_without_roles_view(): void
    {
        $response = $this->actAs($this->limitedAdmin)->getJson('/api/nav');
        $response->assertOk();

        $keys = collect($response->json('groups'))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))
            ->toArray();

        $this->assertNotContains('company-roles', $keys,
            'Management role without roles.view must NOT see Roles nav item');
    }

    public function test_nav_shows_roles_with_roles_view(): void
    {
        $response = $this->actAs($this->manager)->getJson('/api/nav');
        $response->assertOk();

        $keys = collect($response->json('groups'))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))
            ->toArray();

        $this->assertContains('company-roles', $keys,
            'Management role with roles.view should see Roles nav item');
    }

    // ───────────────────────────────────────────────────────────
    // Permission catalog includes roles.* permissions
    // ───────────────────────────────────────────────────────────

    public function test_permission_catalog_includes_roles_permissions(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/company/permissions');
        $response->assertOk();

        $permKeys = collect($response->json('permissions'))->pluck('key')->toArray();

        $this->assertContains('roles.view', $permKeys);
        $this->assertContains('roles.manage', $permKeys);
    }

    public function test_permission_catalog_includes_roles_governance_bundle(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/company/permissions');
        $response->assertOk();

        $modules = $response->json('modules');
        $rolesModule = collect($modules)->firstWhere('module_key', 'core.roles');

        $this->assertNotNull($rolesModule, 'core.roles module must appear in catalog');

        $bundleKeys = collect($rolesModule['capabilities'])->pluck('key')->toArray();
        $this->assertContains('roles.governance', $bundleKeys);
    }
}
