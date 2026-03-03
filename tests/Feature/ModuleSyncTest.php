<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module discovery, sync, and visibility tests.
 */
class ModuleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->admin = PlatformUser::create([
            'first_name' => 'Sync',
            'last_name' => 'Admin',
            'email' => 'syncadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ── Discovery ────────────────────────────────────────

    public function test_core_theme_is_discovered(): void
    {
        ModuleRegistry::clearCache();

        $definitions = ModuleRegistry::definitions();

        $this->assertArrayHasKey('core.theme', $definitions);
        $this->assertEquals('company', $definitions['core.theme']->scope);
        $this->assertEquals('core', $definitions['core.theme']->type);
    }

    public function test_all_core_modules_are_discovered(): void
    {
        ModuleRegistry::clearCache();

        $definitions = ModuleRegistry::definitions();
        $coreKeys = array_filter(
            array_keys($definitions),
            fn ($k) => str_starts_with($k, 'core.'),
        );

        // core.settings, core.members, core.roles, core.jobdomain, core.modules, core.billing, core.theme
        $this->assertGreaterThanOrEqual(7, count($coreKeys));
        $this->assertContains('core.theme', $coreKeys);
    }

    // ── Sync endpoint ─────────────────────────────────────

    public function test_sync_creates_missing_platform_module_row(): void
    {
        // Remove core.theme row to simulate missing row
        PlatformModule::where('key', 'core.theme')->delete();

        $this->assertNull(PlatformModule::where('key', 'core.theme')->first());

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/modules/sync');

        $response->assertOk();

        // Row should now exist
        $row = PlatformModule::where('key', 'core.theme')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->is_enabled_globally);
    }

    public function test_sync_is_idempotent(): void
    {
        // Sync twice, second should not create duplicates
        $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/modules/sync')
            ->assertOk();

        $countBefore = PlatformModule::count();

        $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/modules/sync')
            ->assertOk();

        $this->assertEquals($countBefore, PlatformModule::count());
    }

    public function test_sync_preserves_existing_enabled_state(): void
    {
        // Disable a module
        PlatformModule::where('key', 'core.theme')->update(['is_enabled_globally' => false]);

        $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/modules/sync')
            ->assertOk();

        // Should still be disabled (sync preserves existing state)
        $this->assertFalse(
            PlatformModule::where('key', 'core.theme')->value('is_enabled_globally'),
        );
    }

    public function test_sync_returns_refreshed_module_list(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/modules/sync');

        $response->assertOk()
            ->assertJsonStructure(['company', 'platform']);

        $companyKeys = collect($response->json('company'))->pluck('key')->all();

        $this->assertContains('core.theme', $companyKeys);
    }

    public function test_sync_requires_authentication(): void
    {
        $this->postJson('/api/platform/modules/sync')
            ->assertUnauthorized();
    }

    // ── Visibility in module list ──────────────────────────

    public function test_core_theme_visible_in_platform_modules_list(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/modules');

        $response->assertOk();

        $companyKeys = collect($response->json('company'))->pluck('key')->all();

        $this->assertContains('core.theme', $companyKeys);
    }

    public function test_core_theme_has_correct_type_in_list(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/modules');

        $response->assertOk();

        $theme = collect($response->json('company'))->firstWhere('key', 'core.theme');

        $this->assertNotNull($theme);
        $this->assertEquals('core', $theme['type']);
    }

    public function test_core_theme_visible_in_module_detail(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/modules/core.theme');

        $response->assertOk()
            ->assertJsonPath('module.key', 'core.theme')
            ->assertJsonPath('module.type', 'core')
            ->assertJsonPath('module.scope', 'company');
    }

    // ── Jobdomain integration ──────────────────────────────

    public function test_core_theme_in_jobdomain_module_list(): void
    {
        // The module list returned by fetchModules() is used by the
        // jobdomain selector — if core.theme is in the company list,
        // the jobdomain page will display it.
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/modules');

        $companyModules = collect($response->json('company'));
        $coreTheme = $companyModules->firstWhere('key', 'core.theme');

        $this->assertNotNull($coreTheme);
        // core modules have compatible_jobdomains = null (compatible with all)
        $this->assertNull($coreTheme['compatible_jobdomains']);
    }
}
