<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Theme role visibility: simple toggle to control which roles
 * see the Light/Dark theme switcher in the header.
 *
 * Toggling ON assigns theme.view permission to the role.
 * Toggling OFF revokes it.
 * HeaderWidgetBuilder (ADR-161) filters widgets by this permission.
 */
class ThemeRoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        CompanyPermissionCatalog::sync();
    }

    // ═══════════════════════════════════════════════════════
    // Permission assignment
    // ═══════════════════════════════════════════════════════

    public function test_role_toggle_assigns_permission(): void
    {
        [$user, $company, $role] = $this->createCompanyWithRole();

        // Role starts without theme.view
        $this->assertFalse($role->hasPermission('theme.view'));

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->putJson('/api/theme/role-visibility', [
                'visibility' => [$role->id => true],
            ]);

        $response->assertOk();

        $role->refresh()->load('permissions');
        $this->assertTrue($role->hasPermission('theme.view'),
            'Toggling ON must assign theme.view permission to the role');
    }

    public function test_role_toggle_revokes_permission(): void
    {
        [$user, $company, $role] = $this->createCompanyWithRole();

        // First assign theme.view
        $themeViewPerm = CompanyPermission::where('key', 'theme.view')->first();
        $role->permissions()->attach($themeViewPerm);
        $this->assertTrue($role->hasPermission('theme.view'));

        // Now revoke
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->putJson('/api/theme/role-visibility', [
                'visibility' => [$role->id => false],
            ]);

        $response->assertOk();

        $role->refresh()->load('permissions');
        $this->assertFalse($role->hasPermission('theme.view'),
            'Toggling OFF must revoke theme.view permission from the role');
    }

    // ═══════════════════════════════════════════════════════
    // Widget visibility integration
    // ═══════════════════════════════════════════════════════

    public function test_user_without_theme_view_does_not_receive_widget(): void
    {
        [$owner, $company, $role] = $this->createCompanyWithRole();

        // Create member with role that lacks theme.view
        $member = User::factory()->create();
        $company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actingAs($member)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $widgetKeys = collect($response->json('header_widgets'))->pluck('key')->all();

        $this->assertNotContains('theme-switcher', $widgetKeys,
            'User without theme.view should NOT see the theme-switcher widget');
    }

    public function test_user_with_theme_view_receives_widget(): void
    {
        [$owner, $company, $role] = $this->createCompanyWithRole();

        // Assign theme.view to the role
        $themeViewPerm = CompanyPermission::where('key', 'theme.view')->first();
        $role->permissions()->attach($themeViewPerm);

        // Create member with that role
        $member = User::factory()->create();
        $company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actingAs($member)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $widgetKeys = collect($response->json('header_widgets'))->pluck('key')->all();

        $this->assertContains('theme-switcher', $widgetKeys,
            'User with theme.view should see the theme-switcher widget');
    }

    // ═══════════════════════════════════════════════════════
    // Index endpoint
    // ═══════════════════════════════════════════════════════

    public function test_index_returns_roles_with_visibility(): void
    {
        [$user, $company, $role] = $this->createCompanyWithRole();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/theme/role-visibility');

        $response->assertOk();
        $response->assertJsonStructure([
            'roles' => [['id', 'name', 'key', 'visible']],
        ]);

        $roles = $response->json('roles');
        $found = collect($roles)->firstWhere('id', $role->id);

        $this->assertNotNull($found);
        $this->assertFalse($found['visible'], 'Role without theme.view should have visible=false');
    }

    // ═══════════════════════════════════════════════════════
    // Authorization
    // ═══════════════════════════════════════════════════════

    public function test_non_owner_blocked(): void
    {
        [$owner, $company, $role] = $this->createCompanyWithRole();

        // Create non-administrative role for the member
        $basicRole = CompanyRole::create([
            'company_id' => $company->id,
            'key' => 'basic',
            'name' => 'Basic',
            'is_administrative' => false,
        ]);

        // Create non-management member
        $member = User::factory()->create();
        $company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'user',
            'company_role_id' => $basicRole->id,
        ]);

        $response = $this->actingAs($member)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/theme/role-visibility');

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Idempotency
    // ═══════════════════════════════════════════════════════

    public function test_idempotent_assignment(): void
    {
        [$user, $company, $role] = $this->createCompanyWithRole();

        $payload = ['visibility' => [$role->id => true]];

        // Assign twice
        $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->putJson('/api/theme/role-visibility', $payload)
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->putJson('/api/theme/role-visibility', $payload)
            ->assertOk();

        $role->refresh()->load('permissions');
        $this->assertTrue($role->hasPermission('theme.view'));

        // Count: should only have 1 theme.view permission, not 2
        $themeViewCount = $role->permissions()->where('key', 'theme.view')->count();
        $this->assertSame(1, $themeViewCount, 'Idempotent — no duplicate permission rows');
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function createCompanyWithRole(): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Theme Co', 'slug' => 'theme-co-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $role = CompanyRole::create([
            'company_id' => $company->id,
            'key' => 'viewer',
            'name' => 'Viewer',
            'is_administrative' => true,
        ]);

        return [$user, $company, $role];
    }
}
