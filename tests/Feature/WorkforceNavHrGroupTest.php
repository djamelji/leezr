<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-635 (Workforce 2.0 / W-A) — les entrées Workforce (surface:'hr') sont regroupées sous le groupe
 * « Ressources humaines » (clé `hr`), de façon 100 % manifest-driven : respect des permissions, des
 * modules actifs, pas de doublon, groupe caché si vide, routes inchangées, et aucun déplacement des
 * fonctionnalités non-RH.
 */
class WorkforceNavHrGroupTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFORCE_MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    /** Company dont le jobdomain rend les modules RH éligibles, avec activation des modules voulus. */
    private function companyWith(array $activeModules): Company
    {
        Jobdomain::updateOrCreate(['key' => 'hrtest'], ['label' => 'HR Test', 'default_modules' => array_values(array_unique(array_merge(['workforce'], $activeModules)))]);
        $company = Company::create(['name' => 'HR Nav Co '.uniqid(), 'slug' => 'hrnav-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'hrtest']);

        foreach ($activeModules as $key) {
            CompanyModuleActivationReason::create(['company_id' => $company->id, 'module_key' => $key, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $company->id, 'module_key' => $key, 'is_enabled_for_company' => true]);
        }
        ModuleRegistry::clearCache();

        return $company;
    }

    private function group(array $groups, string $key): ?array
    {
        return collect($groups)->firstWhere('key', $key);
    }

    private function itemKeys(?array $group): array
    {
        return $group ? array_column($group['items'], 'key') : [];
    }

    public function test_workforce_items_land_in_hr_group(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $hr = $this->group($groups, 'hr');
        $this->assertNotNull($hr, 'le groupe RH existe');
        $this->assertSame('nav.groups.hr', $hr['titleKey'], 'titleKey i18n, pas une chaîne en dur');

        $keys = $this->itemKeys($hr);
        $this->assertContains('workforce-employees', $keys);
        $this->assertContains('workforce-leave', $keys);
        $this->assertContains('workforce-planning', $keys);
    }

    public function test_non_hr_items_not_moved_to_hr(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        // Les items RH ne sont PAS dans le groupe générique « company » / « account ».
        foreach (['company', 'account', 'operations'] as $g) {
            $this->assertNotContains('workforce-employees', $this->itemKeys($this->group($groups, $g)), "workforce-employees absent de $g");
        }
        // Un item non-RH (paramètres Core) reste hors du groupe hr.
        $this->assertNotContains('settings', $this->itemKeys($this->group($groups, 'hr')));
    }

    public function test_inactive_module_does_not_contribute(): void
    {
        // Planning NON activé → son entrée n'apparaît pas.
        $company = $this->companyWith(['workforce', 'workforce_leave']);
        $groups = NavBuilder::forCompany($company, null, 'management');
        $keys = $this->itemKeys($this->group($groups, 'hr'));

        $this->assertContains('workforce-leave', $keys);
        $this->assertNotContains('workforce-planning', $keys, 'module planning inactif → pas d\'entrée');
    }

    public function test_permissions_filter_hr_entries(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        // Utilisateur non-bypass avec uniquement leave_request : voit congés + "me" (sans permission), pas employés.
        $groups = NavBuilder::forCompany($company, ['workforce.leave_request'], 'management');
        $keys = $this->itemKeys($this->group($groups, 'hr'));

        $this->assertContains('workforce-leave', $keys);
        $this->assertContains('workforce-me', $keys, 'entrée sans permission visible');
        $this->assertNotContains('workforce-employees', $keys, 'employés nécessite workforce.view');
    }

    public function test_hr_group_disappears_when_empty(): void
    {
        // Aucun module RH actif → pas de groupe hr.
        $company = Company::create(['name' => 'No HR Co', 'slug' => 'nohr-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertNull($this->group($groups, 'hr'), 'groupe RH absent si aucune entrée');
    }

    public function test_routes_unchanged(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');
        $hr = $this->group($groups, 'hr');

        $employees = collect($hr['items'])->firstWhere('key', 'workforce-employees');
        $this->assertNotNull($employees);
        // La route de l'entrée est préservée (nom de route inchangé).
        $to = $employees['to'];
        $name = is_array($to) ? ($to['name'] ?? null) : $to;
        $this->assertSame('company-workforce-employees', $name);
    }

    public function test_no_duplicate_entries(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');
        $keys = $this->itemKeys($this->group($groups, 'hr'));

        $this->assertSame(count($keys), count(array_unique($keys)), 'aucun doublon dans le groupe RH');
    }

    public function test_hr_label_exists_in_all_locales(): void
    {
        foreach (['fr', 'en', 'ar'] as $locale) {
            $json = json_decode(file_get_contents(base_path("resources/js/plugins/i18n/locales/{$locale}.json")), true);
            $this->assertNotEmpty($json['nav']['groups']['hr'] ?? null, "label nav.groups.hr présent en {$locale}");
        }
    }
}
