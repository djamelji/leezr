<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\HeaderWidgetBuilder;
use App\Modules\Core\Theme\ThemeModule;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-161: Header widgets are capability-driven.
 *
 * Modules declare headerWidgets in their Capabilities.
 * HeaderWidgetBuilder collects from active modules, filters by permissions.
 * Nav endpoint returns header_widgets alongside groups.
 * No hardcoded widget logic in frontend layout components.
 */
class HeaderWidgetCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        CompanyPermissionCatalog::sync();
    }

    // ═══════════════════════════════════════════════════════
    // Capabilities VO
    // ═══════════════════════════════════════════════════════

    public function test_capabilities_includes_header_widgets(): void
    {
        $caps = new Capabilities(
            headerWidgets: [
                ['key' => 'test-widget', 'component' => 'TestWidget', 'permission' => 'test.view', 'sortOrder' => 10],
            ],
        );

        $array = $caps->toArray();

        $this->assertArrayHasKey('header_widgets', $array);
        $this->assertCount(1, $array['header_widgets']);
        $this->assertSame('test-widget', $array['header_widgets'][0]['key']);
    }

    // ═══════════════════════════════════════════════════════
    // ThemeModule manifest
    // ═══════════════════════════════════════════════════════

    public function test_theme_module_declares_header_widget(): void
    {
        $manifest = ThemeModule::manifest();

        $this->assertNotEmpty(
            $manifest->capabilities->headerWidgets,
            'ThemeModule must declare headerWidgets capability',
        );

        $widget = $manifest->capabilities->headerWidgets[0];
        $this->assertSame('theme-switcher', $widget['key']);
        $this->assertSame('NavbarThemeSwitcher', $widget['component']);
        $this->assertSame('theme.view', $widget['permission']);
    }

    // ═══════════════════════════════════════════════════════
    // Nav endpoint returns header_widgets
    // ═══════════════════════════════════════════════════════

    public function test_nav_endpoint_returns_header_widgets_for_company(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();
        $response->assertJsonStructure(['groups', 'header_widgets']);
        $this->assertIsArray($response->json('header_widgets'));
    }

    public function test_nav_endpoint_returns_header_widgets_for_platform(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $response->assertOk();
        $response->assertJsonStructure(['groups', 'header_widgets']);
        $this->assertIsArray($response->json('header_widgets'));
    }

    // ═══════════════════════════════════════════════════════
    // Module activation controls widget visibility
    // ═══════════════════════════════════════════════════════

    public function test_disabled_theme_module_hides_widget(): void
    {
        // Disable core.theme globally
        \App\Core\Modules\PlatformModule::where('key', 'core.theme')
            ->update(['is_enabled_globally' => false]);

        ModuleRegistry::clearCache();

        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $widgetKeys = collect($response->json('header_widgets'))->pluck('key')->all();

        $this->assertNotContains('theme-switcher', $widgetKeys,
            'Disabled module widgets should not appear in header_widgets');
    }

    public function test_platform_shows_theme_widget_when_globally_enabled(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/api/platform/nav');

        $response->assertOk();

        $widgetKeys = collect($response->json('header_widgets'))->pluck('key')->all();

        $this->assertContains('theme-switcher', $widgetKeys,
            'Platform nav should include theme-switcher widget from globally enabled core.theme');
    }

    // ═══════════════════════════════════════════════════════
    // Permission filtering
    // ═══════════════════════════════════════════════════════

    public function test_permission_filters_widget(): void
    {
        [$owner, $company] = $this->createCompanyOwner();

        // Create member without theme.view permission
        $member = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $company->id,
            'key' => 'no_theme',
            'name' => 'No Theme',
            'is_administrative' => true,
        ]);

        // Grant some permission but NOT theme.view
        $dashPerm = CompanyPermission::where('key', 'dashboard.view')->first();
        if ($dashPerm) {
            $role->permissions()->attach($dashPerm);
        }

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
            'User without theme.view should not see theme-switcher widget');
    }

    public function test_owner_sees_all_widgets(): void
    {
        [$user, $company] = $this->createCompanyOwner();

        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $widgetKeys = collect($response->json('header_widgets'))->pluck('key')->all();

        $this->assertContains('theme-switcher', $widgetKeys,
            'Owner (null permissions = bypass) should see all widgets');
    }

    // ═══════════════════════════════════════════════════════
    // Frontend invariant — no hardcoded theme checks
    // ═══════════════════════════════════════════════════════

    public function test_navbar_global_widgets_no_hardcoded_theme_check(): void
    {
        $content = file_get_contents(
            base_path('resources/js/layouts/components/NavbarGlobalWidgets.vue'),
        );

        $this->assertStringNotContainsString(
            'moduleStore.isActive',
            $content,
            'NavbarGlobalWidgets must NOT hardcode module activation checks (ADR-161)',
        );

        $this->assertStringNotContainsString(
            'useModuleStore',
            $content,
            'NavbarGlobalWidgets must NOT import useModuleStore (ADR-161: widgets come from nav store)',
        );

        $this->assertStringNotContainsString(
            'useAuthStore',
            $content,
            'NavbarGlobalWidgets must NOT import useAuthStore (ADR-161: permissions filtered by backend)',
        );

        // Must use navStore for dynamic widget rendering
        $this->assertStringContainsString(
            'useNavStore',
            $content,
            'NavbarGlobalWidgets must import useNavStore for capability-driven widget rendering',
        );

        $this->assertStringContainsString(
            'widgetComponents',
            $content,
            'NavbarGlobalWidgets must use a component registry for dynamic rendering',
        );
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function createSuperAdmin(): PlatformUser
    {
        $user = PlatformUser::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-widget@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $user->roles()->attach($superAdmin);

        return $user;
    }

    private function createCompanyOwner(): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Widget Co', 'slug' => 'widget-co-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $company];
    }
}
