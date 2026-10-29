<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use Database\Seeders\DevSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correctif persistance modules DEV (ADR-652) — prouve que les opérations de bootstrap/seeding
 * réellement exécutées en CI/CD (migrate:fresh --seed → SystemSeeder + DevSeeder) laissent
 * Leezr Logistics avec workforce + documents + contract + signature actifs, de façon idempotente,
 * sans auto-activer contract/signature pour les autres companies.
 */
class DevModuleEntitlementPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = ['workforce', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    private function runSeeders(): void
    {
        $this->seed(SystemSeeder::class);
        $this->seed(DevSeeder::class);
    }

    private function leezr(): Company
    {
        return Company::withoutGlobalScopes()->where('slug', 'leezr-logistics')->firstOrFail();
    }

    public function test_leezr_logistics_has_all_four_modules_after_seeding(): void
    {
        $this->runSeeders();
        $company = $this->leezr();
        foreach (self::EXPECTED as $key) {
            $this->assertTrue(ModuleGate::isActive($company, $key), "module {$key} actif pour Leezr Logistics");
        }
    }

    public function test_reseeding_is_idempotent_modules_stay_active(): void
    {
        $this->runSeeders();
        // Simule un second déploiement DEV (ré-exécution du DevSeeder).
        $this->seed(DevSeeder::class);
        $company = $this->leezr();
        foreach (self::EXPECTED as $key) {
            $this->assertTrue(ModuleGate::isActive($company, $key), "module {$key} toujours actif après re-seed");
        }
    }

    public function test_other_companies_not_auto_activated_for_contract_signature(): void
    {
        $this->runSeeders();
        $trial = Company::withoutGlobalScopes()->where('slug', 'trial-logistics')->first();
        if ($trial) {
            $this->assertFalse(ModuleGate::isActive($trial, 'workforce_contract'), 'contract non auto-activé pour trial');
            $this->assertFalse(ModuleGate::isActive($trial, 'workforce_signature'), 'signature non auto-activé pour trial');
        } else {
            $this->markTestSkipped('trial-logistics absent');
        }
    }

    public function test_dev_entitlement_is_marked_as_test_not_commercial(): void
    {
        $this->runSeeders();
        foreach (['workforce_contract', 'workforce_signature'] as $key) {
            $pm = \App\Core\Modules\PlatformModule::where('key', $key)->first();
            $this->assertNotNull($pm);
            $this->assertTrue(($pm->addon_pricing['dev_test'] ?? false) === true, "{$key} addon_pricing marqué dev_test");
        }
    }

    // ── Précondition W-SIGNATURE-2 : un déploiement DEV ordinaire (bootstrap) PRÉSERVE les données ──
    public function test_dev_bootstrap_preserves_recette_data_over_two_deploys(): void
    {
        $this->runSeeders();
        $company = $this->leezr();

        // Donnée de recette : un collaborateur fictif créé pendant les tests.
        $emp = \App\Core\Workforce\Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'first_name' => 'Recette', 'last_name' => 'Persist',
            'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'REC-PERSIST-1',
        ]);
        $before = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->count();

        // Deux déploiements ordinaires successifs = bootstrap idempotent (PAS de migrate:fresh).
        $this->seed(\Database\Seeders\DevBootstrapSeeder::class);
        $this->seed(\Database\Seeders\DevBootstrapSeeder::class);

        // La donnée de recette est PRÉSERVÉE (ni supprimée ni dupliquée).
        $this->assertDatabaseHas('workforce_employees', ['id' => $emp->id, 'employee_number' => 'REC-PERSIST-1']);
        $after = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->count();
        $this->assertSame($before, $after, 'aucun collaborateur supprimé ni dupliqué par le bootstrap');

        // Les activations restent identiques.
        foreach (self::EXPECTED as $key) {
            $this->assertTrue(ModuleGate::isActive($company->fresh(), $key), "module {$key} toujours actif après 2 déploiements");
        }
    }
}
