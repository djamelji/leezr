<?php

namespace Tests\Feature;

use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformRolesCrudTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@roles-test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ─── INDEX ────────────────────────────────────────────

    public function test_can_list_roles(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        // PlatformSeeder creates super_admin + admin = 2 roles
        $response->assertOk()
            ->assertJsonStructure(['roles'])
            ->assertJsonCount(2, 'roles');
    }

    public function test_list_roles_includes_enriched_fields(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        $response->assertOk();

        $roles = $response->json('roles');
        foreach ($roles as $role) {
            $this->assertArrayHasKey('users_count', $role);
            $this->assertArrayHasKey('permissions', $role);
            $this->assertArrayHasKey('is_system', $role);
            $this->assertArrayHasKey('access_level', $role);
            $this->assertArrayHasKey('permissions_count', $role);
            $this->assertArrayHasKey('permissions_grouped', $role);
            $this->assertArrayHasKey('users_sample', $role);
            $this->assertIsArray($role['users_sample']);
        }
    }

    public function test_roles_ordered_super_admin_first(): void
    {
        PlatformRole::create(['key' => 'aaa_first_alpha', 'name' => 'AAA']);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        $response->assertOk();

        $keys = collect($response->json('roles'))->pluck('key')->toArray();
        $this->assertEquals('super_admin', $keys[0], 'super_admin must be first');
        $this->assertEquals('admin', $keys[1], 'admin must be second');
    }

    public function test_super_admin_has_full_access_level(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        $superAdmin = collect($response->json('roles'))->firstWhere('key', 'super_admin');

        $this->assertEquals('full_access', $superAdmin['access_level']);
        $this->assertTrue($superAdmin['is_system']);
        $this->assertGreaterThan(0, $superAdmin['permissions_count']);
    }

    public function test_admin_has_administration_level(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        $admin = collect($response->json('roles'))->firstWhere('key', 'admin');

        $this->assertEquals('administration', $admin['access_level']);
        $this->assertTrue($admin['is_system']);
    }

    public function test_custom_role_with_admin_perms_has_management_level(): void
    {
        $adminPerm = PlatformPermission::where('is_admin', true)->first();

        if (!$adminPerm) {
            $this->markTestSkipped('No is_admin permission found in platform catalog.');
        }

        $role = PlatformRole::create(['key' => 'manager_test', 'name' => 'Manager Test']);
        $role->permissions()->attach($adminPerm->id);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        $manager = collect($response->json('roles'))->firstWhere('key', 'manager_test');

        $this->assertEquals('management', $manager['access_level']);
        $this->assertFalse($manager['is_system']);
    }

    public function test_permissions_grouped_by_module(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/roles');

        $admin = collect($response->json('roles'))->firstWhere('key', 'admin');

        $this->assertNotEmpty($admin['permissions_grouped']);

        foreach ($admin['permissions_grouped'] as $group) {
            $this->assertArrayHasKey('module_key', $group);
            $this->assertArrayHasKey('module_name', $group);
            $this->assertArrayHasKey('module_icon', $group);
            $this->assertArrayHasKey('permissions', $group);
            $this->assertNotEmpty($group['permissions']);

            foreach ($group['permissions'] as $perm) {
                $this->assertArrayHasKey('id', $perm);
                $this->assertArrayHasKey('key', $perm);
                $this->assertArrayHasKey('label', $perm);
                $this->assertArrayHasKey('is_admin', $perm);
            }
        }
    }

    public function test_store_returns_enriched_role(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'key' => 'enriched_test',
                'name' => 'Enriched Test',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'role' => [
                    'id', 'key', 'name', 'is_system', 'access_level',
                    'permissions_count', 'users_count', 'permissions_grouped',
                ],
            ]);

        $this->assertFalse($response->json('role.is_system'));
        $this->assertEquals('custom', $response->json('role.access_level'));
    }

    public function test_update_returns_enriched_role(): void
    {
        $role = PlatformRole::create(['key' => 'update_enriched', 'name' => 'Before']);
        $perm = PlatformPermission::first();
        $role->permissions()->attach($perm->id);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/roles/{$role->id}", [
                'name' => 'After',
            ]);

        $response->assertOk()
            ->assertJsonPath('role.name', 'After')
            ->assertJsonStructure([
                'role' => [
                    'is_system', 'access_level', 'permissions_count', 'permissions_grouped',
                ],
            ]);
    }

    // ─── STORE ────────────────────────────────────────────

    public function test_can_create_role(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'key' => 'viewer',
                'name' => 'Viewer',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role.key', 'viewer')
            ->assertJsonPath('role.name', 'Viewer');

        $this->assertDatabaseHas('platform_roles', ['key' => 'viewer', 'name' => 'Viewer']);
    }

    public function test_can_create_role_with_permissions(): void
    {
        $permission = PlatformPermission::first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'key' => 'limited_admin',
                'name' => 'Limited Admin',
                'permissions' => [$permission->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('role.key', 'limited_admin');

        $role = PlatformRole::where('key', 'limited_admin')->first();
        $this->assertCount(1, $role->permissions);
        $this->assertEquals($permission->id, $role->permissions->first()->id);
    }

    public function test_store_validation_requires_key(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'name' => 'No Key Role',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    public function test_store_validation_requires_name(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'key' => 'no_name',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_store_validation_rejects_duplicate_key(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'key' => 'super_admin',
                'name' => 'Duplicate Super Admin',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    public function test_store_validation_rejects_nonexistent_permission_id(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/roles', [
                'key' => 'bad_perms',
                'name' => 'Bad Perms',
                'permissions' => [99999],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    // ─── UPDATE ───────────────────────────────────────────

    public function test_can_update_role_name(): void
    {
        $role = PlatformRole::create(['key' => 'editable', 'name' => 'Old Name']);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/roles/{$role->id}", [
                'name' => 'New Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('role.name', 'New Name');

        $this->assertDatabaseHas('platform_roles', ['id' => $role->id, 'name' => 'New Name']);
    }

    public function test_can_update_role_permissions(): void
    {
        $role = PlatformRole::create(['key' => 'perm_update', 'name' => 'Perm Update']);
        $permissions = PlatformPermission::take(2)->pluck('id')->toArray();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/roles/{$role->id}", [
                'permissions' => $permissions,
            ]);

        $response->assertOk();

        $role->refresh();
        $this->assertCount(2, $role->permissions);
    }

    public function test_cannot_modify_super_admin_permissions(): void
    {
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $permission = PlatformPermission::first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/roles/{$superAdmin->id}", [
                'permissions' => [$permission->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
    }

    public function test_can_rename_super_admin(): void
    {
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/roles/{$superAdmin->id}", [
                'name' => 'Supreme Admin',
            ]);

        $response->assertOk()
            ->assertJsonPath('role.name', 'Supreme Admin');
    }

    public function test_update_rejects_duplicate_key(): void
    {
        $role = PlatformRole::create(['key' => 'unique_test', 'name' => 'Unique Test']);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/roles/{$role->id}", [
                'key' => 'super_admin',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    // ─── DESTROY ──────────────────────────────────────────

    public function test_can_delete_unassigned_role(): void
    {
        $role = PlatformRole::create(['key' => 'deletable', 'name' => 'Deletable']);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/roles/{$role->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Role deleted.');

        $this->assertDatabaseMissing('platform_roles', ['key' => 'deletable']);
    }

    public function test_cannot_delete_super_admin(): void
    {
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/roles/{$superAdmin->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('platform_roles', ['key' => 'super_admin']);
    }

    public function test_cannot_delete_role_with_users_attached(): void
    {
        $admin = PlatformRole::where('key', 'admin')->first();

        // PlatformSeeder attaches users to admin — so it has users_count > 0
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/roles/{$admin->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('platform_roles', ['key' => 'admin']);
    }

    // ─── PERMISSION GUARD ─────────────────────────────────

    public function test_requires_manage_roles_permission(): void
    {
        $unprivileged = PlatformUser::create([
            'first_name' => 'No',
            'last_name' => 'Perms',
            'email' => 'noperms@roles-test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response = $this->actingAs($unprivileged, 'platform')
            ->getJson('/api/platform/roles');

        $response->assertStatus(403);
    }
}
