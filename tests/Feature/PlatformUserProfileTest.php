<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformUserProfileTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;
    private PlatformUser $target;
    private PlatformRole $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->superAdminRole = PlatformRole::where('key', 'super_admin')->first();

        // Admin with manage_platform_users + manage_platform_user_credentials
        $this->admin = PlatformUser::factory()->create();
        $this->admin->roles()->attach($this->superAdminRole);

        // Target user (no super_admin)
        $this->target = PlatformUser::factory()->create();
    }

    private function actAs(PlatformUser $user)
    {
        return $this->actingAs($user, 'platform');
    }

    // ─── 1) Show returns base + dynamic fields ──────────

    public function test_platform_user_profile_show_returns_base_and_dynamic(): void
    {
        $response = $this->actAs($this->admin)
            ->getJson("/api/platform/platform-users/{$this->target->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'base_fields' => ['id', 'first_name', 'last_name', 'display_name', 'email', 'status', 'roles'],
                'dynamic_fields',
            ]);

        $this->assertEquals($this->target->first_name, $response->json('base_fields.first_name'));
        $this->assertEquals($this->target->email, $response->json('base_fields.email'));
    }

    // ─── 2) Update base + dynamic fields ─────────────────

    public function test_platform_user_profile_update_base_and_dynamic(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/platform/platform-users/{$this->target->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Person',
            ]);

        $response->assertOk();

        $this->target->refresh();
        $this->assertEquals('Updated', $this->target->first_name);
        $this->assertEquals('Person', $this->target->last_name);
    }

    // ─── 3) Requires permission ──────────────────────────

    public function test_platform_user_profile_requires_permission(): void
    {
        $unprivileged = PlatformUser::factory()->create();

        $response = $this->actAs($unprivileged)
            ->getJson("/api/platform/platform-users/{$this->target->id}");

        $response->assertStatus(403);
    }

    // ─── 4) Email unique validation ──────────────────────

    public function test_platform_user_profile_email_unique_validation(): void
    {
        $response = $this->actAs($this->admin)
            ->putJson("/api/platform/platform-users/{$this->target->id}", [
                'email' => $this->admin->email,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── 5) Cannot set password on super_admin ───────────

    public function test_credentials_cannot_modify_super_admin(): void
    {
        $superAdmin = PlatformUser::factory()->create();
        $superAdmin->roles()->attach($this->superAdminRole);

        // setPassword
        $response = $this->actAs($this->admin)
            ->putJson("/api/platform/platform-users/{$superAdmin->id}/password", [
                'password' => 'Xk9#mW4!qZ7pL2v',
                'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
            ]);

        $response->assertStatus(403);

        // adminResetPassword
        $response = $this->actAs($this->admin)
            ->postJson("/api/platform/platform-users/{$superAdmin->id}/reset-password");

        $response->assertStatus(403);
    }

    // ─── 6) Cannot modify own credentials ────────────────

    public function test_credentials_cannot_modify_self(): void
    {
        // Create a non-super_admin user with credential permission
        $credAdmin = PlatformUser::factory()->create();
        $credRole = PlatformRole::create(['name' => 'Cred Manager', 'key' => 'cred_manager']);
        $credPerm = PlatformPermission::where('key', 'manage_platform_user_credentials')->first();
        if ($credPerm) {
            $credRole->permissions()->attach($credPerm);
        }
        $credAdmin->roles()->attach($credRole);

        // setPassword on self
        $response = $this->actAs($credAdmin)
            ->putJson("/api/platform/platform-users/{$credAdmin->id}/password", [
                'password' => 'Xk9#mW4!qZ7pL2v',
                'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
            ]);

        $response->assertStatus(403);

        // adminResetPassword on self
        $response = $this->actAs($credAdmin)
            ->postJson("/api/platform/platform-users/{$credAdmin->id}/reset-password");

        $response->assertStatus(403);
    }

    // ─── 7) Credential routes still protected by permission ──

    public function test_credentials_routes_unchanged_and_still_protected(): void
    {
        // User with manage_platform_users but NOT manage_platform_user_credentials
        $limitedAdmin = PlatformUser::factory()->create();
        $limitedRole = PlatformRole::create(['name' => 'User Manager', 'key' => 'user_mgr']);
        $userPerm = PlatformPermission::where('key', 'manage_platform_users')->first();
        if ($userPerm) {
            $limitedRole->permissions()->attach($userPerm);
        }
        $limitedAdmin->roles()->attach($limitedRole);

        // setPassword should be 403 (no credential permission)
        $response = $this->actAs($limitedAdmin)
            ->putJson("/api/platform/platform-users/{$this->target->id}/password", [
                'password' => 'Xk9#mW4!qZ7pL2v',
                'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
            ]);

        $response->assertStatus(403);

        // adminResetPassword should be 403 (no credential permission)
        $response = $this->actAs($limitedAdmin)
            ->postJson("/api/platform/platform-users/{$this->target->id}/reset-password");

        $response->assertStatus(403);
    }
}
