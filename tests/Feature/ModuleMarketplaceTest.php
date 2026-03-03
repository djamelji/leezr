<?php

namespace Tests\Feature;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Core\Theme\ThemeModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-162: Module Marketplace (App Store Model).
 *
 * Tests capability-driven settingsPanels, catalog category derivation,
 * and settings_panels in catalog response.
 */
class ModuleMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    // ═══════════════════════════════════════════════════════
    // Capabilities VO — settingsPanels
    // ═══════════════════════════════════════════════════════

    public function test_default_capabilities_has_empty_settings_panels(): void
    {
        $caps = new Capabilities();

        $this->assertSame([], $caps->settingsPanels);
        $this->assertArrayHasKey('settings_panels', $caps->toArray());
        $this->assertSame([], $caps->toArray()['settings_panels']);
    }

    public function test_capabilities_includes_settings_panels_in_to_array(): void
    {
        $caps = new Capabilities(
            settingsPanels: [
                ['key' => 'test-panel', 'component' => 'TestPanel', 'label' => 'Test', 'icon' => 'tabler-test', 'permission' => 'test.manage', 'sortOrder' => 10],
            ],
        );

        $array = $caps->toArray();

        $this->assertArrayHasKey('settings_panels', $array);
        $this->assertCount(1, $array['settings_panels']);
        $this->assertSame('test-panel', $array['settings_panels'][0]['key']);
        $this->assertSame('TestPanel', $array['settings_panels'][0]['component']);
    }

    public function test_capabilities_backward_compatible_without_settings_panels(): void
    {
        // Existing constructors that don't pass settingsPanels should still work
        $caps = new Capabilities(
            navItems: [['key' => 'test']],
            headerWidgets: [['key' => 'widget']],
        );

        $this->assertSame([], $caps->settingsPanels);
        $this->assertCount(1, $caps->navItems);
        $this->assertCount(1, $caps->headerWidgets);
    }

    // ═══════════════════════════════════════════════════════
    // ThemeModule manifest — settingsPanels declaration
    // ═══════════════════════════════════════════════════════

    public function test_theme_module_declares_settings_panel(): void
    {
        $manifest = ThemeModule::manifest();
        $panels = $manifest->capabilities->settingsPanels;

        $this->assertCount(1, $panels);
        $this->assertSame('theme-role-visibility', $panels[0]['key']);
        $this->assertSame('ThemeRoleVisibility', $panels[0]['component']);
        $this->assertSame('theme.manage', $panels[0]['permission']);
        $this->assertArrayHasKey('sortOrder', $panels[0]);
        $this->assertArrayHasKey('label', $panels[0]);
        $this->assertArrayHasKey('icon', $panels[0]);
    }

    public function test_theme_module_still_declares_header_widget(): void
    {
        $manifest = ThemeModule::manifest();
        $widgets = $manifest->capabilities->headerWidgets;

        $this->assertCount(1, $widgets);
        $this->assertSame('theme-switcher', $widgets[0]['key']);
    }

    // ═══════════════════════════════════════════════════════
    // Catalog — category derivation + settings_panels
    // ═══════════════════════════════════════════════════════

    public function test_catalog_includes_category_field(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Cat Co', 'slug' => 'cat-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        $this->assertNotEmpty($catalog);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('category', $module, "Module {$module['key']} missing category");
            $this->assertContains($module['category'], ['core', 'addon', 'premium', 'industry'],
                "Module {$module['key']} has invalid category: {$module['category']}");
        }
    }

    public function test_core_modules_have_core_category(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Core Co', 'slug' => 'core-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);
        $coreTheme = collect($catalog)->firstWhere('key', 'core.theme');

        $this->assertNotNull($coreTheme);
        $this->assertSame('core', $coreTheme['category']);
        $this->assertSame('core', $coreTheme['type']);
    }

    public function test_catalog_includes_settings_panels(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Panel Co', 'slug' => 'panel-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('settings_panels', $module, "Module {$module['key']} missing settings_panels");
            $this->assertIsArray($module['settings_panels']);
        }
    }

    public function test_theme_module_catalog_has_settings_panel(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Theme Co', 'slug' => 'theme-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);
        $coreTheme = collect($catalog)->firstWhere('key', 'core.theme');

        $this->assertNotNull($coreTheme);
        $this->assertCount(1, $coreTheme['settings_panels']);
        $this->assertSame('ThemeRoleVisibility', $coreTheme['settings_panels'][0]['component']);
    }

    public function test_modules_without_panels_have_empty_settings_panels(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Empty Co', 'slug' => 'empty-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);
        $coreSettings = collect($catalog)->firstWhere('key', 'core.settings');

        $this->assertNotNull($coreSettings);
        $this->assertSame([], $coreSettings['settings_panels']);
    }
}
