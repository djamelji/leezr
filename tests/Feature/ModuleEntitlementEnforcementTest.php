<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleDisplayState;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-204: Module entitlement enforcement tests.
 *
 * Verifies that ModuleGate enforces EntitlementResolver dynamically,
 * not via stale cache. Also tests reconciliation and catalog override.
 */
class ModuleEntitlementEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Jobdomain $jobdomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->jobdomain = Jobdomain::updateOrCreate(
            ['key' => 'logistique'],
            [
                'label' => 'Logistique',
                'is_active' => true,
                'default_modules' => [
                    'logistics_shipments',
                    'logistics_tracking',
                    'logistics_fleet',
                    'logistics_analytics',
                ],
                'allow_custom_fields' => true,
            ],
        );

        $this->company = Company::create([
            'name' => 'Enforcement Co',
            'slug' => 'enforcement-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->jobdomains()->sync([$this->jobdomain->id]);

        // Enable core modules with activation reasons
        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if ($manifest->type === 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
                CompanyModule::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'is_enabled_for_company' => true,
                ]);
            }
        }
    }

    /**
     * Module active, then removed from jobdomain defaults → gate must block.
     */
    public function test_gate_blocks_module_removed_from_jobdomain_defaults(): void
    {
        // Activate shipments
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));

        // Platform admin removes shipments from jobdomain defaults
        $this->jobdomain->update([
            'default_modules' => ['logistics_tracking', 'logistics_fleet', 'logistics_analytics'],
        ]);
        $this->company->unsetRelation('jobdomain');

        // Gate must now block — even though activation reason still exists
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    /**
     * Module active, then made incompatible via compatible_jobdomains_override → gate must block.
     */
    public function test_gate_blocks_incompatible_jobdomain_override(): void
    {
        // Activate shipments
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        $this->assertTrue(ModuleGate::isActive($this->company, 'logistics_shipments'));

        // Platform admin overrides compatible jobdomains to exclude logistique
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['compatible_jobdomains_override' => json_encode(['construction'])]);

        // Gate must now block
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    /**
     * Module without activation reason → gate must block even if entitled.
     */
    public function test_gate_requires_activation_reason(): void
    {
        // Shipments is in defaults (entitled) but has no activation reason
        $entitlement = EntitlementResolver::check($this->company, 'logistics_shipments');
        $this->assertTrue($entitlement['entitled']);

        // No activation reason → gate blocks
        $this->assertFalse(ModuleGate::isActive($this->company, 'logistics_shipments'));
    }

    /**
     * Reconcile command deactivates non-entitled modules and cleans up data.
     */
    public function test_reconcile_deactivates_non_entitled_modules(): void
    {
        // Activate shipments
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        // Remove from defaults
        $this->jobdomain->update([
            'default_modules' => ['logistics_tracking', 'logistics_fleet', 'logistics_analytics'],
        ]);

        // Run reconciliation
        $this->artisan('modules:reconcile-entitlements', [
            '--company' => $this->company->id,
        ])->assertExitCode(0);

        // Activation reasons cleaned up
        $this->assertFalse(
            CompanyModuleActivationReason::where('company_id', $this->company->id)
                ->where('module_key', 'logistics_shipments')
                ->exists(),
        );

        // Cache synced
        $cm = CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->first();

        $this->assertNotNull($cm);
        $this->assertFalse($cm->is_enabled_for_company);
    }

    /**
     * Dry-run does not modify data.
     */
    public function test_reconcile_dry_run_does_not_modify(): void
    {
        // Activate shipments
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        // Remove from defaults
        $this->jobdomain->update([
            'default_modules' => ['logistics_tracking', 'logistics_fleet', 'logistics_analytics'],
        ]);

        // Run dry-run
        $this->artisan('modules:reconcile-entitlements', [
            '--company' => $this->company->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        // Activation reasons still exist
        $this->assertTrue(
            CompanyModuleActivationReason::where('company_id', $this->company->id)
                ->where('module_key', 'logistics_shipments')
                ->exists(),
        );
    }

    /**
     * ADR-205/206: Module removed from defaults but still compatible.
     * With addon_pricing → LOCKED_ADDON. Without → CONTACT_SALES.
     */
    public function test_display_state_shows_addon_not_contact_sales_when_removed_from_defaults(): void
    {
        // Activate shipments and set addon_pricing
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['addon_pricing' => json_encode(['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 50]])]);

        // Catalog should show shipments as INCLUDED (it's in defaults)
        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNotNull($shipments);
        $this->assertEquals(ModuleDisplayState::INCLUDED->value, $shipments['display_state']);

        // Remove shipments from defaults (but keep it compatible with logistique)
        $this->jobdomain->update([
            'default_modules' => ['logistics_tracking', 'logistics_fleet', 'logistics_analytics'],
        ]);
        $this->company->unsetRelation('jobdomain');

        // ADR-206: Module has addon_pricing → LOCKED_ADDON
        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNotNull($shipments, 'Module should still appear in catalog (compatible jobdomain)');
        $this->assertEquals(ModuleDisplayState::LOCKED_ADDON->value, $shipments['display_state']);
        $this->assertEquals('addon', $shipments['purchase_mode']);

        // Without addon_pricing → CONTACT_SALES
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['addon_pricing' => null]);

        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNotNull($shipments);
        $this->assertEquals(ModuleDisplayState::CONTACT_SALES->value, $shipments['display_state']);
    }

    /**
     * Incompatible jobdomain → module excluded from catalog entirely.
     */
    public function test_display_state_excludes_incompatible_module_from_catalog(): void
    {
        // Override to make shipments incompatible with logistique
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['compatible_jobdomains_override' => json_encode(['construction'])]);

        // Module should not appear in catalog at all
        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNull($shipments, 'Incompatible module should be excluded from catalog');
    }

    /**
     * ModuleCatalogReadModel uses compatible_jobdomains_override from DB.
     */
    public function test_catalog_uses_compatible_jobdomains_override(): void
    {
        // Activate shipments
        ModuleActivationEngine::enable($this->company, 'logistics_shipments');

        // Catalog should include shipments (logistique is compatible)
        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNotNull($shipments);

        // Override to exclude logistique
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['compatible_jobdomains_override' => json_encode(['construction'])]);

        // Catalog must now exclude shipments
        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNull($shipments);
    }

    /**
     * ADR-208: Catalog includes reverse dependency info (dependents).
     */
    public function test_catalog_includes_dependents_for_shipments(): void
    {
        $catalog = ModuleCatalogReadModel::forCompany($this->company);
        $shipments = collect($catalog)->firstWhere('key', 'logistics_shipments');
        $this->assertNotNull($shipments);

        // logistics_shipments is required by tracking, fleet, analytics
        $this->assertIsArray($shipments['dependents']);
        $this->assertNotEmpty($shipments['dependents']);
        $this->assertContains('logistics_tracking', $shipments['dependents']);

        // logistics_tracking has no dependents
        $tracking = collect($catalog)->firstWhere('key', 'logistics_tracking');
        $this->assertNotNull($tracking);
        $this->assertEmpty($tracking['dependents']);

        // logistics_tracking requires logistics_shipments
        $this->assertContains('logistics_shipments', $tracking['requires']);
    }

    /**
     * ADR-206: effectivePricingModeFor returns correct derived value.
     */
    public function test_effective_pricing_mode_derived_correctly(): void
    {
        // Core module → 'included'
        $corePm = PlatformModule::where('key', 'core.members')->first();
        $this->assertNotNull($corePm);
        $this->assertEquals('included', $corePm->effectivePricingModeFor($this->company));

        // Module in jobdomain defaults → 'included'
        $shipmentsPm = PlatformModule::where('key', 'logistics_shipments')->first();
        $this->assertNotNull($shipmentsPm);
        $this->assertEquals('included', $shipmentsPm->effectivePricingModeFor($this->company));

        // Module with addon_pricing → 'addon'
        $trackingPm = PlatformModule::where('key', 'logistics_tracking')->first();
        $trackingPm->update(['addon_pricing' => ['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 10]]]);
        // Tracking is in defaults AND has addon_pricing → defaults wins (included)
        $this->assertEquals('included', $trackingPm->effectivePricingModeFor($this->company));

        // Remove tracking from defaults, keep addon_pricing
        $this->jobdomain->update([
            'default_modules' => ['logistics_shipments', 'logistics_fleet', 'logistics_analytics'],
        ]);
        $this->company->unsetRelation('jobdomain');

        // Now tracking has addon_pricing but not in defaults → 'addon'
        $this->assertEquals('addon', $trackingPm->effectivePricingModeFor($this->company));

        // Module with no addon_pricing and not in defaults → 'contact_sales'
        $trackingPm->update(['addon_pricing' => null]);
        $this->assertEquals('contact_sales', $trackingPm->effectivePricingModeFor($this->company));
    }
}
