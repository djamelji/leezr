<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * ADR-159: Theme module invariants.
 */
class ThemeModuleTest extends TestCase
{
    public function test_theme_module_is_registered(): void
    {
        $modules = ModuleRegistry::definitions();

        $this->assertArrayHasKey('core.theme', $modules);
    }

    public function test_theme_module_is_company_scope(): void
    {
        $manifest = ModuleRegistry::definitions()['core.theme'];

        $this->assertEquals('company', $manifest->scope);
    }

    public function test_theme_module_is_core_type(): void
    {
        $manifest = ModuleRegistry::definitions()['core.theme'];

        $this->assertEquals('core', $manifest->type);
    }

    public function test_theme_module_has_correct_permissions(): void
    {
        $manifest = ModuleRegistry::definitions()['core.theme'];
        $permKeys = array_column($manifest->permissions, 'key');

        $this->assertContains('theme.view', $permKeys);
        $this->assertContains('theme.manage', $permKeys);
    }

    public function test_theme_module_has_correct_bundles(): void
    {
        $manifest = ModuleRegistry::definitions()['core.theme'];
        $bundleKeys = array_column($manifest->bundles, 'key');

        $this->assertContains('theme.full', $bundleKeys);
        $this->assertContains('theme.readonly', $bundleKeys);
    }

    public function test_theme_module_has_no_nav_items(): void
    {
        $manifest = ModuleRegistry::definitions()['core.theme'];

        $this->assertEmpty($manifest->capabilities->navItems);
    }

    public function test_theme_module_in_jobdomain_defaults(): void
    {
        $definition = \App\Core\Jobdomains\JobdomainRegistry::get('logistique');

        $this->assertContains('core.theme', $definition['default_modules']);
    }
}
