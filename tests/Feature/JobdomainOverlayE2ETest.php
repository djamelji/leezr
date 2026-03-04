<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainMarketOverlay;
use App\Core\Jobdomains\JobdomainPresetResolver;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-194: E2E validation — Jobdomain overlay → Company assignment.
 *
 * Verifies that the full chain works:
 *   Jobdomain defaults + Market overlay → JobdomainPresetResolver → JobdomainGate::assignToCompany → Company state.
 */
class JobdomainOverlayE2ETest extends TestCase
{
    use RefreshDatabase;

    private Jobdomain $jobdomain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        ModuleRegistry::sync();
        JobdomainPresetResolver::clearCache();

        // Create a test jobdomain with known defaults
        $this->jobdomain = Jobdomain::create([
            'key' => 'e2e_transport',
            'label' => 'E2E Transport',
            'is_active' => true,
            'default_modules' => ['core.members', 'core.roles'],
            'default_fields' => [
                ['code' => 'phone', 'order' => 0],
                ['code' => 'siret', 'order' => 1],
            ],
            'default_documents' => [
                ['code' => 'id_card', 'order' => 0],
            ],
            'default_roles' => [
                'admin' => [
                    'name' => 'Admin',
                    'is_administrative' => true,
                    'bundles' => [],
                    'permissions' => ['members.view', 'members.manage'],
                ],
                'driver' => [
                    'name' => 'Driver',
                    'is_administrative' => false,
                    'bundles' => [],
                    'permissions' => ['members.view'],
                ],
            ],
        ]);
    }

    // ─── E2E: Company WITHOUT overlay (global defaults) ──────

    public function test_company_without_overlay_gets_global_defaults(): void
    {
        $company = $this->createCompany('GB');

        JobdomainGate::assignToCompany($company, 'e2e_transport');

        // Modules: core.members + core.roles
        $activatedModules = CompanyModule::where('company_id', $company->id)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        $this->assertContains('core.members', $activatedModules);
        $this->assertContains('core.roles', $activatedModules);

        // Fields: phone + siret
        $activatedFieldCodes = $this->getActivatedFieldCodes($company);

        $this->assertContains('phone', $activatedFieldCodes);
        $this->assertContains('siret', $activatedFieldCodes);

        // Documents: id_card
        $activatedDocCodes = $this->getActivatedDocCodes($company);

        $this->assertContains('id_card', $activatedDocCodes);

        // Roles: admin + driver
        $roles = CompanyRole::where('company_id', $company->id)->pluck('key')->toArray();

        $this->assertContains('admin', $roles);
        $this->assertContains('driver', $roles);
    }

    // ─── E2E: Company WITH overlay (market FR) ───────────────

    public function test_company_with_overlay_gets_merged_presets(): void
    {
        // Create overlay for market FR:
        // - Add core.settings module
        // - Remove core.roles module
        // - Override field: change siret order
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'e2e_transport',
            'market_key' => 'FR',
            'override_modules' => ['core.settings'],
            'remove_modules' => ['core.roles'],
            'override_fields' => [
                ['code' => 'siret', 'order' => 10],
            ],
            'remove_fields' => [],
            'override_documents' => [],
            'remove_documents' => [],
            'override_roles' => [],
            'remove_roles' => [],
        ]);

        JobdomainPresetResolver::clearCache();

        $company = $this->createCompany('FR');

        JobdomainGate::assignToCompany($company, 'e2e_transport');

        // Modules: core.members + core.settings (core.roles removed)
        $activatedModules = CompanyModule::where('company_id', $company->id)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        $this->assertContains('core.members', $activatedModules, 'Global module kept');
        $this->assertContains('core.settings', $activatedModules, 'Override module added');
        $this->assertNotContains('core.roles', $activatedModules, 'Removed module absent');

        // No duplicate activation reasons
        $reasonCount = CompanyModuleActivationReason::where('company_id', $company->id)
            ->where('module_key', 'core.members')
            ->count();

        $this->assertEquals(1, $reasonCount, 'No duplicate activation reasons');

        // Fields: phone (order 0) + siret (order overridden to 10)
        $activatedFieldCodes = $this->getActivatedFieldCodes($company);

        $this->assertContains('phone', $activatedFieldCodes);
        $this->assertContains('siret', $activatedFieldCodes);

        // Verify siret order was overridden
        $siretDef = FieldDefinition::whereNull('company_id')->where('code', 'siret')->first();
        if ($siretDef) {
            $siretActivation = FieldActivation::where('company_id', $company->id)
                ->where('field_definition_id', $siretDef->id)
                ->first();

            $this->assertEquals(10, $siretActivation->order, 'Overlay overrides field order');
        }

        // Documents: id_card (not overridden, not removed)
        $activatedDocCodes = $this->getActivatedDocCodes($company);

        $this->assertContains('id_card', $activatedDocCodes);

        // Roles: both still present (admin is administrative = cannot be removed)
        $roles = CompanyRole::where('company_id', $company->id)->pluck('key')->toArray();

        $this->assertContains('admin', $roles);
        $this->assertContains('driver', $roles);
    }

    // ─── Overlay removes document ────────────────────────────

    public function test_overlay_can_remove_documents(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'e2e_transport',
            'market_key' => 'FR',
            'override_modules' => [],
            'remove_modules' => [],
            'override_fields' => [],
            'remove_fields' => [],
            'override_documents' => [],
            'remove_documents' => ['id_card'],
            'override_roles' => [],
            'remove_roles' => [],
        ]);

        JobdomainPresetResolver::clearCache();

        $company = $this->createCompany('FR');

        JobdomainGate::assignToCompany($company, 'e2e_transport');

        $activatedDocCodes = $this->getActivatedDocCodes($company);

        $this->assertNotContains('id_card', $activatedDocCodes, 'Overlay removed document');
    }

    // ─── Cannot remove administrative role via overlay ───────

    public function test_overlay_cannot_remove_administrative_role(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'e2e_transport',
            'market_key' => 'FR',
            'override_modules' => [],
            'remove_modules' => [],
            'override_fields' => [],
            'remove_fields' => [],
            'override_documents' => [],
            'remove_documents' => [],
            'override_roles' => [],
            'remove_roles' => ['admin', 'driver'],
        ]);

        JobdomainPresetResolver::clearCache();

        $company = $this->createCompany('FR');

        JobdomainGate::assignToCompany($company, 'e2e_transport');

        $roles = CompanyRole::where('company_id', $company->id)->pluck('key')->toArray();

        $this->assertContains('admin', $roles, 'Administrative role cannot be removed');
        $this->assertNotContains('driver', $roles, 'Non-administrative role removed');
    }

    // ─── Two companies same jobdomain different markets ──────

    public function test_two_companies_different_markets_get_different_presets(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => 'e2e_transport',
            'market_key' => 'FR',
            'override_modules' => ['core.settings'],
            'remove_modules' => [],
            'override_fields' => [],
            'remove_fields' => [],
            'override_documents' => [],
            'remove_documents' => [],
            'override_roles' => [],
            'remove_roles' => [],
        ]);

        JobdomainPresetResolver::clearCache();

        $companyFR = $this->createCompany('FR', 'fr-co');
        $companyGB = $this->createCompany('GB', 'gb-co');

        JobdomainGate::assignToCompany($companyFR, 'e2e_transport');

        JobdomainPresetResolver::clearCache();

        JobdomainGate::assignToCompany($companyGB, 'e2e_transport');

        $modulesFR = CompanyModule::where('company_id', $companyFR->id)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        $modulesGB = CompanyModule::where('company_id', $companyGB->id)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        // FR has core.settings (overlay), GB does not
        $this->assertContains('core.settings', $modulesFR);
        $this->assertNotContains('core.settings', $modulesGB);

        // Both have global defaults
        $this->assertContains('core.members', $modulesFR);
        $this->assertContains('core.members', $modulesGB);
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function createCompany(string $marketKey, string $slug = 'e2e-co'): Company
    {
        $owner = User::factory()->create();

        $company = Company::create([
            'name' => 'E2E Company',
            'slug' => $slug,
            'market_key' => $marketKey,
            'jobdomain_key' => 'logistique',
            'plan_key' => 'pro',
        ]);

        $company->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        return $company;
    }

    private function getActivatedFieldCodes(Company $company): array
    {
        return FieldActivation::where('company_id', $company->id)
            ->where('enabled', true)
            ->get()
            ->map(fn ($a) => FieldDefinition::find($a->field_definition_id)?->code)
            ->filter()
            ->toArray();
    }

    private function getActivatedDocCodes(Company $company): array
    {
        return DocumentTypeActivation::where('company_id', $company->id)
            ->where('enabled', true)
            ->get()
            ->map(fn ($a) => DocumentType::find($a->document_type_id)?->code)
            ->filter()
            ->toArray();
    }
}
