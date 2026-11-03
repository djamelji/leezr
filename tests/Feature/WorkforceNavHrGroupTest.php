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
 * ADR-690 (WORKFORCE EXPERIENCE 2026 / E2) : navigation métier orientée ACTIVITÉ, 100 % manifest-driven.
 * Toutes les entrées de management RH vivent dans un groupe unique `workforce` (« Ressources humaines »),
 * ordonnées par activité (Collaborateurs, Temps, Congés, Documents, Paie, Organisation, Paramètres).
 * Chaque entrée n'apparaît que si l'utilisateur possède RÉELLEMENT une capacité dans l'espace
 * (permission simple ou anyPermission). « Mon espace » reste dans le groupe personnel `account`.
 * Les routes historiques restent inchangées (deep-links). Les groupes vides sont élagués, pas de doublon.
 */
class WorkforceNavHrGroupTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFORCE_MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    /** Mapping attendu item → groupe d'activité. Tout le management RH est consolidé dans `workforce`. */
    private const EXPECTED_GROUP = [
        'workforce-employees' => 'workforce',
        'workforce-time' => 'workforce',
        'workforce-leave' => 'workforce',
        'workforce-payroll' => 'workforce',
        'workforce-documents' => 'workforce',
        'workforce-organization' => 'workforce',
        'workforce-settings' => 'workforce',
        // « Mon espace » = self-service personnel, hors management RH.
        'workforce-me' => 'account',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

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

    private function itemKeys(array $groups, string $groupKey): array
    {
        $g = $this->group($groups, $groupKey);

        return $g ? array_column($g['items'], 'key') : [];
    }

    /** Résout le groupe contenant un item donné (ou null). */
    private function groupOf(array $groups, string $itemKey): ?string
    {
        foreach ($groups as $g) {
            if (in_array($itemKey, array_column($g['items'], 'key'), true)) {
                return $g['key'];
            }
        }

        return null;
    }

    public function test_workforce_items_land_in_the_activity_group(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        foreach (self::EXPECTED_GROUP as $item => $expectedGroup) {
            $this->assertSame($expectedGroup, $this->groupOf($groups, $item), "{$item} doit être dans {$expectedGroup}");
        }

        // Le titre de la section « Ressources humaines » est une clé i18n (pas une chaîne en dur).
        $this->assertSame('nav.groups.workforce', $this->group($groups, 'workforce')['titleKey']);

        // Plus aucune entrée RH ne tombe dans le fallback historique `hr` ni dans les anciens groupes mono-item.
        foreach (['hr', 'hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings'] as $gk) {
            $this->assertNull($this->group($groups, $gk), "l'ancien groupe {$gk} est élagué (consolidé dans workforce)");
        }
    }

    public function test_activity_group_is_ordered_by_activity(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        // L'ordre reflète le parcours d'activité : Collaborateurs → Temps → Congés → Documents → Paie → Organisation → Paramètres.
        $this->assertSame(
            ['workforce-employees', 'workforce-time', 'workforce-leave', 'workforce-documents', 'workforce-payroll', 'workforce-organization', 'workforce-settings'],
            $this->itemKeys($groups, 'workforce'),
        );
    }

    public function test_my_space_is_in_personal_account_group(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertSame('account', $this->groupOf($groups, 'workforce-me'), 'Mon espace = groupe personnel');
        $this->assertNotContains('workforce-me', $this->itemKeys($groups, 'workforce'), 'Mon espace n\'est pas mêlé au RH management');
    }

    public function test_inactive_module_does_not_contribute(): void
    {
        // Paie + planning NON activés → leurs entrées n'apparaissent pas dans le groupe workforce.
        $company = $this->companyWith(['workforce', 'workforce_leave']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertSame('workforce', $this->groupOf($groups, 'workforce-leave'));
        $this->assertNull($this->groupOf($groups, 'workforce-payroll'), 'module paie inactif → pas d\'entrée');
    }

    /**
     * E2 : chaque entrée n'apparaît que si l'utilisateur possède RÉELLEMENT une capacité dans l'espace.
     * Vérifie plusieurs combinaisons de permissions (pas de persona en dur).
     */
    public function test_entries_appear_only_with_a_real_capability(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);

        // (a) RH congés seulement : voit Congés + « Mon espace », PAS employés/temps/paie.
        $g = NavBuilder::forCompany($company, ['workforce.leave_request'], 'management');
        $this->assertSame('workforce', $this->groupOf($g, 'workforce-leave'));
        $this->assertSame('account', $this->groupOf($g, 'workforce-me'), 'entrée self-service sans permission visible');
        $this->assertNull($this->groupOf($g, 'workforce-employees'), 'employés nécessite workforce.view');
        $this->assertNull($this->groupOf($g, 'workforce-time'), 'temps nécessite une capacité temps/planning');
        $this->assertNull($this->groupOf($g, 'workforce-payroll'), 'paie nécessite une capacité paie');

        // (b) Responsable d'exploitation : planning/pointage seulement → voit Temps, pas Paie ni Employés.
        $g = NavBuilder::forCompany($company, ['workforce.planning_manage', 'workforce.time_manage'], 'management');
        $this->assertSame('workforce', $this->groupOf($g, 'workforce-time'), 'anyPermission temps satisfait par planning_manage/time_manage');
        $this->assertNull($this->groupOf($g, 'workforce-payroll'));
        $this->assertNull($this->groupOf($g, 'workforce-employees'));

        // (c) Gestionnaire paie sans members.manage : voit Paie, pas Employés.
        $g = NavBuilder::forCompany($company, ['workforce.payroll_prepare'], 'management');
        $this->assertSame('workforce', $this->groupOf($g, 'workforce-payroll'), 'anyPermission paie satisfait par payroll_prepare');
        $this->assertNull($this->groupOf($g, 'workforce-employees'));
        $this->assertNull($this->groupOf($g, 'workforce-time'));

        // (d) timesheet_view seul : voit Temps (capacité pointage).
        $g = NavBuilder::forCompany($company, ['workforce.timesheet_view'], 'management');
        $this->assertSame('workforce', $this->groupOf($g, 'workforce-time'));
    }

    public function test_task_group_disappears_when_empty(): void
    {
        // Aucun module RH actif → aucun groupe d'activité workforce.
        $company = Company::create(['name' => 'No HR Co', 'slug' => 'nohr-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        foreach (['workforce', 'hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings', 'hr'] as $gk) {
            $this->assertNull($this->group($groups, $gk), "groupe {$gk} absent si aucune entrée");
        }
    }

    public function test_routes_unchanged(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $expectedRoutes = [
            'workforce-employees' => 'company-workforce-employees',
            'workforce-leave' => 'company-workforce-leave',
            'workforce-time' => 'company-workforce-time',
            'workforce-documents' => 'company-workforce-documents',
            'workforce-payroll' => 'company-workforce-payroll',
        ];
        foreach ($expectedRoutes as $itemKey => $route) {
            $g = $this->group($groups, self::EXPECTED_GROUP[$itemKey]);
            $item = collect($g['items'])->firstWhere('key', $itemKey);
            $this->assertNotNull($item, "{$itemKey} présent");
            $to = $item['to'];
            $this->assertSame($route, is_array($to) ? ($to['name'] ?? null) : $to);
        }
    }

    public function test_no_duplicate_entries(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $allKeys = collect($groups)->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
        $this->assertSame(count($allKeys), count(array_unique($allKeys)), 'aucun doublon de clé de nav');
    }

    public function test_activity_group_label_exists_in_all_locales(): void
    {
        foreach (['fr', 'en', 'ar'] as $locale) {
            $json = json_decode(file_get_contents(base_path("resources/js/plugins/i18n/locales/{$locale}.json")), true);
            $this->assertNotEmpty($json['nav']['groups']['workforce'] ?? null, "label nav.groups.workforce présent en {$locale}");
        }
    }
}
