<?php

namespace Tests\Feature;

use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed platform RBAC for role-dependent tests
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    public function test_platform_login_with_valid_credentials(): void
    {
        $user = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/platform/login', [
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'roles', 'permissions']);
    }

    public function test_platform_login_with_invalid_credentials(): void
    {
        PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/platform/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    public function test_platform_me_returns_user_with_roles_and_permissions(): void
    {
        $user = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $user->roles()->attach($superAdmin);

        $response = $this->actingAs($user, 'platform')->getJson('/api/platform/me');

        $response->assertOk()
            ->assertJsonStructure(['user', 'roles', 'permissions']);
    }

    public function test_platform_routes_require_auth(): void
    {
        $response = $this->getJson('/api/platform/me');

        $response->assertStatus(401);
    }

    public function test_platform_user_created_without_password(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')->postJson('/api/platform/platform-users', [
            'name' => 'New User',
            'email' => 'new@test.com',
        ]);

        $response->assertStatus(201);

        $newUser = PlatformUser::where('email', 'new@test.com')->first();
        $this->assertNotNull($newUser);
        $this->assertNull($newUser->getRawOriginal('password'));
    }

    public function test_platform_forgot_password_always_succeeds(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/platform/forgot-password', [
            'email' => 'nonexistent@test.com',
        ]);

        $response->assertOk();
    }

    public function test_platform_user_created_with_invite_false_and_password(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')->postJson('/api/platform/platform-users', [
            'name' => 'Direct User',
            'email' => 'direct@test.com',
            'invite' => false,
            'password' => 'Xk9#mW4!qZ7pL2v',
            'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Platform user created with password.');

        $user = PlatformUser::where('email', 'direct@test.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->getRawOriginal('password'));
        $this->assertEquals('active', $user->status);
    }

    public function test_platform_user_created_with_invite_true_has_null_password(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')->postJson('/api/platform/platform-users', [
            'name' => 'Invited User',
            'email' => 'invited@test.com',
            'invite' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Platform user created. Invitation sent.');

        $user = PlatformUser::where('email', 'invited@test.com')->first();
        $this->assertNull($user->getRawOriginal('password'));
        $this->assertEquals('invited', $user->status);
    }

    public function test_set_platform_user_password(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $target = PlatformUser::create([
            'name' => 'Target',
            'email' => 'target@test.com',
            'password' => null,
        ]);

        $this->assertEquals('invited', $target->status);

        $response = $this->actingAs($admin, 'platform')->putJson("/api/platform/platform-users/{$target->id}/password", [
            'password' => 'Xk9#mW4!qZ7pL2v',
            'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password set for Target.');

        $target->refresh();
        $this->assertNotNull($target->getRawOriginal('password'));
        $this->assertEquals('active', $target->status);
    }

    public function test_set_password_requires_credential_permission(): void
    {
        // Create a user with manage_platform_users but NOT manage_platform_user_credentials
        $admin = PlatformUser::create([
            'name' => 'Limited Admin',
            'email' => 'limited@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $role = PlatformRole::create(['name' => 'User Manager', 'key' => 'user_manager']);
        $manageUsersPermission = \App\Platform\Models\PlatformPermission::where('key', 'manage_platform_users')->first();
        if ($manageUsersPermission) {
            $role->permissions()->attach($manageUsersPermission);
        }
        $admin->roles()->attach($role);

        $target = PlatformUser::create([
            'name' => 'Target',
            'email' => 'target@test.com',
            'password' => null,
        ]);

        $response = $this->actingAs($admin, 'platform')->putJson("/api/platform/platform-users/{$target->id}/password", [
            'password' => 'Xk9#mW4!qZ7pL2v',
            'password_confirmation' => 'Xk9#mW4!qZ7pL2v',
        ]);

        $response->assertStatus(403);
    }

    public function test_platform_user_json_does_not_expose_password(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')->getJson('/api/platform/platform-users');

        $response->assertOk();

        // Verify no password field in any user object
        $users = $response->json('data');
        foreach ($users as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayHasKey('status', $user);
        }
    }
}
