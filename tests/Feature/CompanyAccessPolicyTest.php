<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Company\Security\CompanyAccess;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CompanyAccess — unified access layer tests.
 *
 * Covers:
 *   - Owner bypass (all abilities)
 *   - access-surface: operational blocked on structure
 *   - access-surface: management allowed on structure
 *   - use-module: inactive module blocked
 *   - use-module: active module allowed
 *   - use-permission: denied without permission
 *   - use-permission: granted with permission
 *   - manage-structure: operational blocked
 *   - manage-structure: administrative allowed
 */
class CompanyAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $driver;
    private User $noRole;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        FieldDefinitionCatalog::sync();
        CompanyPermissionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->driver = User::factory()->create();
        $this->noRole = User::factory()->create();

        $this->company = Company::create(['name' => 'Access Co', 'slug' => 'access-co']);

        // Enable all modules
        foreach (ModuleRegistry::definitions() as $key => $def) {
            CompanyModule::create([
                'company_id' => $this->company->id,
                'module_key' => $key,
                'is_enabled_for_company' => true,
            ]);
        }

        // Administrative role (manager)
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $managerRole->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        // Operational role (driver)
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $driverRole->permissions()->sync(
            CompanyPermission::whereIn('key', ['shipments.view', 'shipments.create'])
                ->pluck('id')->toArray(),
        );

        // Memberships
        $this->company->memberships()->create([
            'user_id' => $this->owner->id, 'role' => 'owner',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->manager->id, 'role' => 'user',
            'company_role_id' => $managerRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->driver->id, 'role' => 'user',
            'company_role_id' => $driverRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->noRole->id, 'role' => 'user',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // Owner bypass
    // ═══════════════════════════════════════════════════════

    public function test_owner_bypasses_role_abilities(): void
    {
        $this->assertTrue(CompanyAccess::can($this->owner, $this->company, 'access-surface', ['surface' => 'structure']));
        $this->assertTrue(CompanyAccess::can($this->owner, $this->company, 'use-permission', ['permission' => 'shipments.create']));
        $this->assertTrue(CompanyAccess::can($this->owner, $this->company, 'manage-structure'));
    }

    public function test_owner_cannot_bypass_module_check(): void
    {
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        $this->assertFalse(CompanyAccess::can($this->owner, $this->company, 'use-module', ['module' => 'logistics_shipments']));
    }

    // ═══════════════════════════════════════════════════════
    // access-surface
    // ═══════════════════════════════════════════════════════

    public function test_operational_blocked_on_structure_surface(): void
    {
        $this->assertFalse(CompanyAccess::can($this->driver, $this->company, 'access-surface', ['surface' => 'structure']));
    }

    public function test_management_allowed_on_structure_surface(): void
    {
        $this->assertTrue(CompanyAccess::can($this->manager, $this->company, 'access-surface', ['surface' => 'structure']));
    }

    public function test_operations_surface_allowed_for_all(): void
    {
        $this->assertTrue(CompanyAccess::can($this->driver, $this->company, 'access-surface', ['surface' => 'operations']));
        $this->assertTrue(CompanyAccess::can($this->noRole, $this->company, 'access-surface', ['surface' => 'operations']));
    }

    // ═══════════════════════════════════════════════════════
    // use-module
    // ═══════════════════════════════════════════════════════

    public function test_active_module_allowed(): void
    {
        $this->assertTrue(CompanyAccess::can($this->driver, $this->company, 'use-module', ['module' => 'logistics_shipments']));
    }

    public function test_inactive_module_blocked(): void
    {
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        $this->assertFalse(CompanyAccess::can($this->driver, $this->company, 'use-module', ['module' => 'logistics_shipments']));
    }

    // ═══════════════════════════════════════════════════════
    // use-permission
    // ═══════════════════════════════════════════════════════

    public function test_permission_granted_with_role(): void
    {
        $this->assertTrue(CompanyAccess::can($this->driver, $this->company, 'use-permission', ['permission' => 'shipments.view']));
    }

    public function test_permission_denied_without_role(): void
    {
        $this->assertFalse(CompanyAccess::can($this->driver, $this->company, 'use-permission', ['permission' => 'members.manage']));
    }

    public function test_permission_denied_no_role_member(): void
    {
        $this->assertFalse(CompanyAccess::can($this->noRole, $this->company, 'use-permission', ['permission' => 'shipments.view']));
    }

    // ═══════════════════════════════════════════════════════
    // manage-structure
    // ═══════════════════════════════════════════════════════

    public function test_operational_cannot_manage_structure(): void
    {
        $this->assertFalse(CompanyAccess::can($this->driver, $this->company, 'manage-structure'));
    }

    public function test_administrative_can_manage_structure(): void
    {
        $this->assertTrue(CompanyAccess::can($this->manager, $this->company, 'manage-structure'));
    }

    public function test_no_role_cannot_manage_structure(): void
    {
        $this->assertFalse(CompanyAccess::can($this->noRole, $this->company, 'manage-structure'));
    }

    // ═══════════════════════════════════════════════════════
    // Middleware integration (via HTTP)
    // ═══════════════════════════════════════════════════════

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    public function test_middleware_structure_blocks_operational(): void
    {
        $response = $this->actAs($this->driver)->getJson('/api/company/roles');

        $response->assertStatus(403);
    }

    public function test_middleware_structure_allows_administrative(): void
    {
        $response = $this->actAs($this->manager)->getJson('/api/company/roles');

        $response->assertOk();
    }

    public function test_middleware_permission_blocks_without_permission(): void
    {
        $response = $this->actAs($this->driver)
            ->postJson('/api/company/members', ['email' => 'test@test.dev']);

        $response->assertStatus(403);
    }

    public function test_middleware_module_blocks_when_inactive(): void
    {
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        $response = $this->actAs($this->driver)->getJson('/api/shipments');

        $response->assertStatus(403);
    }
}
