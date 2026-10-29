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
}
