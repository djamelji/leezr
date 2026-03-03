<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-159: Theme preference persistence tests.
 */
class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Theme Co', 'slug' => 'theme-co', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        $this->owner = User::factory()->create();

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
    }

    // ── Company scope ──────────────────────────────────────────

    public function test_owner_can_set_theme_to_light(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'light']);

        $response->assertOk();
        $response->assertJson(['theme_preference' => 'light']);

        $this->assertEquals('light', $this->owner->fresh()->theme_preference);
    }

    public function test_owner_can_set_theme_to_dark(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'dark']);

        $response->assertOk();
        $response->assertJson(['theme_preference' => 'dark']);

        $this->assertEquals('dark', $this->owner->fresh()->theme_preference);
    }

    public function test_owner_can_set_theme_to_system(): void
    {
        $this->owner->update(['theme_preference' => 'dark']);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'system']);

        $response->assertOk();
        $response->assertJson(['theme_preference' => 'system']);

        $this->assertEquals('system', $this->owner->fresh()->theme_preference);
    }

    public function test_invalid_theme_rejected(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'neon']);

        $response->assertUnprocessable();
    }

    public function test_preference_persists_across_requests(): void
    {
        $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'light'])
            ->assertOk();

        $meResponse = $this->actingAs($this->owner)
            ->getJson('/api/me');

        $meResponse->assertOk();
        $meResponse->assertJsonPath('theme_preference', 'light');
    }

    public function test_module_disabled_globally_blocks_theme_update(): void
    {
        // core.theme is type=core → always active per-company.
        // To block it, disable it globally.
        PlatformModule::where('key', 'core.theme')
            ->update(['is_enabled_globally' => false]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'light']);

        $response->assertForbidden();
    }

    public function test_user_without_permission_blocked(): void
    {
        $user = User::factory()->create();

        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'viewer_no_theme',
            'name' => 'Viewer No Theme',
        ]);

        $viewPerm = CompanyPermission::where('key', 'settings.view')->first();

        if ($viewPerm) {
            $role->permissions()->sync([$viewPerm->id]);
        }

        $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/theme-preference', ['theme' => 'dark']);

        $response->assertForbidden();
    }

    // ── Platform scope ─────────────────────────────────────────

    public function test_platform_admin_can_set_theme(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $adminRole = PlatformRole::where('key', 'admin')->first()
            ?? PlatformRole::create(['key' => 'admin', 'name' => 'Admin']);

        $platformUser->roles()->syncWithoutDetaching([$adminRole->id]);

        $response = $this->actingAs($platformUser, 'platform')
            ->putJson('/api/platform/theme-preference', ['theme' => 'dark']);

        $response->assertOk();
        $response->assertJson(['theme_preference' => 'dark']);

        $this->assertEquals('dark', $platformUser->fresh()->theme_preference);
    }

    public function test_platform_me_includes_theme_preference(): void
    {
        $platformUser = PlatformUser::factory()->create(['theme_preference' => 'light']);
        $adminRole = PlatformRole::where('key', 'admin')->first()
            ?? PlatformRole::create(['key' => 'admin', 'name' => 'Admin']);

        $platformUser->roles()->syncWithoutDetaching([$adminRole->id]);

        $response = $this->actingAs($platformUser, 'platform')
            ->getJson('/api/platform/me');

        $response->assertOk();
        $response->assertJsonPath('theme_preference', 'light');
    }

    // ── Default value ──────────────────────────────────────────

    public function test_default_theme_is_system(): void
    {
        $user = User::factory()->create();

        // DB default is 'system', fresh read from database
        $this->assertEquals('system', $user->fresh()->theme_preference);
    }
}
