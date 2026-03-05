<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleDisplayState;
use App\Core\Modules\ModuleDisplayStateResolver;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-163: ModuleDisplayStateResolver — 9-step resolution pipeline.
 *
 * Tests each path of the display state resolver plus catalog integration.
 */
class ModuleDisplayStateResolverTest extends TestCase
{
    use RefreshDatabase;

    private Jobdomain $defaultJobdomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        // ADR-167a: resolve() requires non-nullable Jobdomain
        $this->defaultJobdomain = new Jobdomain([
            'key' => 'test',
            'label' => 'Test',
            'default_modules' => [],
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function makeManifest(array $overrides = []): ModuleManifest
    {
        return new ModuleManifest(
            key: $overrides['key'] ?? 'test.module',
            name: $overrides['name'] ?? 'Test Module',
            description: $overrides['description'] ?? 'A test module',
            surface: $overrides['surface'] ?? 'operations',
            sortOrder: $overrides['sortOrder'] ?? 100,
            capabilities: $overrides['capabilities'] ?? new Capabilities(),
            permissions: $overrides['permissions'] ?? [],
            bundles: $overrides['bundles'] ?? [],
            scope: $overrides['scope'] ?? 'company',
            type: $overrides['type'] ?? 'addon',
            hidden: $overrides['hidden'] ?? false,
            minPlan: $overrides['minPlan'] ?? null,
            marketplace: $overrides['marketplace'] ?? [],
        );
    }

    private function makePlatformModule(array $overrides = []): PlatformModule
    {
        return new PlatformModule(array_merge([
            'key' => 'test.module',
            'name' => 'Test Module',
            'description' => 'A test module',
            'is_enabled_globally' => true,
            'sort_order' => 100,
            'addon_pricing' => null,
            'min_plan_override' => null,
        ], $overrides));
    }

    private function entitlement(bool $entitled = true, ?string $source = 'plan', ?string $reason = null): array
    {
        return [
            'entitled' => $entitled,
            'source' => $source,
            'reason' => $reason ?? ($entitled ? 'plan_included' : 'unknown_module'),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // Step 1: Hidden manifest → SYSTEM
    // ═══════════════════════════════════════════════════════

    public function test_hidden_module_returns_system(): void
    {
        $manifest = $this->makeManifest(['hidden' => true]);
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(), true, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::SYSTEM, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 2: Globally disabled → SYSTEM
    // ═══════════════════════════════════════════════════════

    public function test_globally_disabled_returns_system(): void
    {
        $manifest = $this->makeManifest();
        $pm = $this->makePlatformModule(['is_enabled_globally' => false]);

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(), true, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::SYSTEM, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 3: Plan requirement not met → LOCKED_PLAN
    // ═══════════════════════════════════════════════════════

    public function test_plan_requirement_not_met_returns_locked_plan(): void
    {
        $manifest = $this->makeManifest(['minPlan' => 'pro']);
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(), true, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::LOCKED_PLAN, $state);
    }

    public function test_plan_lock_takes_priority_over_activation(): void
    {
        // Even if the module is "active" and "entitled", plan lock wins
        $manifest = $this->makeManifest(['minPlan' => 'business']);
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(true), true, $this->defaultJobdomain, 'pro',
        );

        $this->assertSame(ModuleDisplayState::LOCKED_PLAN, $state);
    }

    public function test_plan_met_does_not_lock(): void
    {
        $manifest = $this->makeManifest(['minPlan' => 'pro']);
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(true), true, $this->defaultJobdomain, 'pro',
        );

        // Should NOT be LOCKED_PLAN — it should progress further
        $this->assertNotSame(ModuleDisplayState::LOCKED_PLAN, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 4 (ADR-206): Core module → INCLUDED
    // ═══════════════════════════════════════════════════════

    public function test_core_module_returns_included(): void
    {
        $manifest = $this->makeManifest(['type' => 'core']);
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(), true, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::INCLUDED, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 6: Module in jobdomain defaults → INCLUDED
    // ═══════════════════════════════════════════════════════

    public function test_jobdomain_default_module_returns_included(): void
    {
        $manifest = $this->makeManifest(['key' => 'logistics.fleet']);
        $pm = $this->makePlatformModule(['key' => 'logistics.fleet']);

        $jobdomain = new \App\Core\Jobdomains\Jobdomain([
            'key' => 'transport',
            'label' => 'Transport',
            'description' => 'Transport',
            'default_modules' => ['logistics.fleet'],
        ]);

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(), false, $jobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::INCLUDED, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 7: Active + entitled → ACTIVE
    // ═══════════════════════════════════════════════════════

    public function test_active_entitled_returns_active(): void
    {
        $manifest = $this->makeManifest();
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(true), true, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::ACTIVE, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 8: Entitled but not activated → AVAILABLE
    // ═══════════════════════════════════════════════════════

    public function test_entitled_not_active_returns_available(): void
    {
        $manifest = $this->makeManifest();
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(true), false, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::AVAILABLE, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 8 (ADR-206): addon_pricing ≠ null → LOCKED_ADDON
    // ═══════════════════════════════════════════════════════

    public function test_addon_pricing_returns_locked_addon(): void
    {
        $manifest = $this->makeManifest();
        $pm = $this->makePlatformModule([
            'addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]],
        ]);

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(false), false, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::LOCKED_ADDON, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Step 9: Fallback → CONTACT_SALES
    // ═══════════════════════════════════════════════════════

    public function test_fallback_returns_contact_sales(): void
    {
        $manifest = $this->makeManifest();
        $pm = $this->makePlatformModule();

        $state = ModuleDisplayStateResolver::resolve(
            $manifest, $pm, $this->entitlement(false), false, $this->defaultJobdomain, 'starter',
        );

        $this->assertSame(ModuleDisplayState::CONTACT_SALES, $state);
    }

    // ═══════════════════════════════════════════════════════
    // Catalog integration — ADR-163 fields
    // ═══════════════════════════════════════════════════════

    public function test_catalog_includes_display_state_field(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'DS Co', 'slug' => 'ds-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        $this->assertNotEmpty($catalog);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('display_state', $module, "Module {$module['key']} missing display_state");
            $this->assertContains($module['display_state'], [
                'included', 'active', 'available', 'locked_plan', 'locked_addon', 'contact_sales',
            ], "Module {$module['key']} has unexpected display_state: {$module['display_state']}");
        }
    }

    public function test_catalog_excludes_system_modules(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Sys Co', 'slug' => 'sys-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            $this->assertNotSame('system', $module['display_state'],
                "SYSTEM module {$module['key']} should not be in catalog");
        }
    }

    public function test_catalog_has_upgrade_target_plan_for_locked_plan(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Lock Co', 'slug' => 'lock-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('upgrade_target_plan', $module);

            if ($module['display_state'] === 'locked_plan') {
                $this->assertNotNull($module['upgrade_target_plan'],
                    "Module {$module['key']} is locked_plan but has null upgrade_target_plan");
            } else {
                $this->assertNull($module['upgrade_target_plan'],
                    "Module {$module['key']} is {$module['display_state']} but has non-null upgrade_target_plan");
            }
        }
    }

    public function test_catalog_has_purchase_mode_field(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'PM Co', 'slug' => 'pm-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('purchase_mode', $module);

            $expected = match ($module['display_state']) {
                'locked_plan' => 'plan',
                'locked_addon' => 'addon',
                'contact_sales' => 'sales',
                default => null,
            };

            $this->assertSame($expected, $module['purchase_mode'],
                "Module {$module['key']} ({$module['display_state']}) purchase_mode mismatch");
        }
    }

    public function test_catalog_has_is_included_field(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Inc Co', 'slug' => 'inc-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('is_included', $module);
            $this->assertSame(
                $module['display_state'] === 'included',
                $module['is_included'],
                "Module {$module['key']} is_included mismatch with display_state",
            );
        }
    }

    public function test_catalog_has_is_featured_field(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Feat Co', 'slug' => 'feat-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            $this->assertArrayHasKey('is_featured', $module);
            $this->assertIsBool($module['is_featured']);
        }
    }

    public function test_catalog_backward_compatible_fields_still_present(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'BC Co', 'slug' => 'bc-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);

        foreach ($catalog as $module) {
            // ADR-162 fields still present
            $this->assertArrayHasKey('is_entitled', $module);
            $this->assertArrayHasKey('is_active', $module);
            $this->assertArrayHasKey('category', $module);
            $this->assertArrayHasKey('settings_panels', $module);
        }
    }

    public function test_core_modules_are_included_in_catalog(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Core Co2', 'slug' => 'core-co2', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $catalog = ModuleCatalogReadModel::forCompany($company);
        $coreModules = array_filter($catalog, fn ($m) => $m['type'] === 'core');

        $this->assertNotEmpty($coreModules);

        foreach ($coreModules as $module) {
            $this->assertSame('included', $module['display_state'],
                "Core module {$module['key']} should be 'included' but got '{$module['display_state']}'");
        }
    }
}
