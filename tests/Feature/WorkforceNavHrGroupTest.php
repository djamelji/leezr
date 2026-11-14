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
        // ADR-754 (P2) : l'entrée « Employés » est remplacée par « Collaborateurs » (Core, groupe
        // Entreprise) — plus d'entrée workforce-employees dans le groupe RH.
        // ADR-766 (M7) : l'entrée « Documents » RH est consolidée dans l'espace Documents unifié (Core,
        // groupe Entreprise, onglet RH) — plus d'entrée workforce-documents dans le groupe RH.
        'workforce-time' => 'time',
        'workforce-leave' => 'time',
        'workforce-payroll' => 'payroll',
        'workforce-organization' => 'settings',
        'workforce-settings' => 'settings',
        // « Mon espace » = self-service personnel, hors management RH.
        'workforce-me' => 'personal',
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

    /** ADR-775 : feuilles d'un espace d'intention (l'entrée d'espace porte ses items en sous-entrées). */
    private function itemKeys(array $groups, string $groupKey): array
    {
        $keys = [];
        foreach ($groups as $g) {
            if (preg_replace('/_menu$/', '', $g['key']) !== $groupKey) {
                continue;
            }
            foreach ($g['items'] as $item) {
                $keys = array_merge($keys, $item['children'] ? array_column($item['children'], 'key') : [$item['key']]);
            }
        }

        return $keys;
    }

    /** Résout l'espace contenant un item donné (ou null) — menu latéral ou menu de l'avatar. */
    private function groupOf(array $groups, string $itemKey): ?string
    {
        foreach ($groups as $g) {
            $space = preg_replace('/_menu$/', '', $g['key']);
            if (in_array($itemKey, $this->itemKeys($groups, $space), true)) {
                return $space;
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
        // ADR-775 : l'espace « Planning & temps » est UNE entrée libellée par une clé i18n (pas une chaîne en dur).
        $this->assertSame('nav.groups.time', $this->group($groups, 'time')['items'][0]['labelKey']);
        $this->assertNull($this->group($groups, 'workforce'), 'plus de section « Ressources humaines » calquée sur le module');

        // Plus aucune entrée RH ne tombe dans le fallback historique `hr` ni dans les anciens groupes mono-item.
        foreach (['hr', 'hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings'] as $gk) {
            $this->assertNull($this->group($groups, $gk), "l'ancien groupe {$gk} est élagué (consolidé dans workforce)");
        }
    }

    public function test_activity_group_is_ordered_by_activity(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        // L'ordre reflète le parcours d'activité : Temps → Planning → Congés → Paie → Organisation → Paramètres.
        // ADR-712 : item « Planning » dédié (sort 25) intercalé après « Temps de travail ».
        // ADR-766 : « Documents » RH n'est plus une entrée de nav (onglet de l'espace Documents unifié).
$this->assertSame(['workforce-planning', 'workforce-time', 'workforce-leave'], $this->itemKeys($groups, 'time'));
        $this->assertSame(['workforce-payroll'], $this->itemKeys($groups, 'payroll'));
        $this->assertSame(['workforce-organization', 'workforce-settings'], array_values(array_intersect($this->itemKeys($groups, 'settings'), ['workforce-organization', 'workforce-settings'])));
    }

    public function test_my_space_is_in_personal_account_group(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertSame('personal', $this->groupOf($groups, 'workforce-me'), 'Mon espace = groupe personnel');
        $this->assertNotContains('workforce-me', array_merge($this->itemKeys($groups, 'time'), $this->itemKeys($groups, 'team'), $this->itemKeys($groups, 'settings')), 'Mon espace n\'est pas mêlé au management');
    }

    public function test_inactive_module_does_not_contribute(): void
    {
        // Paie + planning NON activés → leurs entrées n'apparaissent pas dans le groupe workforce.
        $company = $this->companyWith(['workforce', 'workforce_leave']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertSame('time', $this->groupOf($groups, 'workforce-leave'));
        $this->assertNull($this->groupOf($groups, 'workforce-payroll'), 'module paie inactif → pas d\'entrée');
    }

    /**
     * E2 : chaque entrée n'apparaît que si l'utilisateur possède RÉELLEMENT une capacité dans l'espace.
     * Vérifie plusieurs combinaisons de permissions (pas de persona en dur).
     */
    public function test_entries_appear_only_with_a_real_capability(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);

        // (a) Gestion des absences (leave_request + leave_approve) : voit Congés + « Mon espace »,
        // PAS employés/temps/paie. leave_request SEUL ne suffit pas (vue management = view_all/approve).
        $g = NavBuilder::forCompany($company, ['workforce.leave_request', 'workforce.leave_approve'], 'management');
        $this->assertSame('time', $this->groupOf($g, 'workforce-leave'));
        $this->assertSame('personal', $this->groupOf($g, 'workforce-me'), 'entrée self-service sans permission visible');
        $this->assertNull($this->groupOf($g, 'workforce-employees'), 'employés nécessite workforce.view');
        $this->assertNull($this->groupOf($g, 'workforce-time'), 'temps nécessite une capacité temps/planning');
        $this->assertNull($this->groupOf($g, 'workforce-payroll'), 'paie nécessite une capacité paie');

        // (a-bis) leave_request SEUL (salarié) : pas de menu Congés management.
        $g = NavBuilder::forCompany($company, ['workforce.leave_request'], 'management');
        $this->assertNull($this->groupOf($g, 'workforce-leave'), 'leave_request seul → pas de menu management');

        // (b) Responsable d'exploitation (planning_view + planning_manage) : ADR-729 (M6.A) — le planning
        // PRÉVISIONNEL a sa propre entrée « Planning » (distincte de « Temps & présences » = pointage réel).
        // Il voit donc « Planning », PAS « Temps » (qui exige désormais timesheet_view), ni Paie ni Employés.
        $g = NavBuilder::forCompany($company, ['workforce.planning_view', 'workforce.planning_manage'], 'management');
        $this->assertSame('time', $this->groupOf($g, 'workforce-planning'), 'planning_view → entrée Planning dédiée');
        $this->assertNull($this->groupOf($g, 'workforce-time'), 'planning_view seul n’ouvre plus « Temps & présences »');
        $this->assertNull($this->groupOf($g, 'workforce-payroll'));
        $this->assertNull($this->groupOf($g, 'workforce-employees'));

        // (b-bis) Capacité de GESTION sans capacité de LECTURE (time_manage seul) : PAS de menu Temps.
        // L'index pointages exige timesheet_view → sans elle l'espace est vide/403 → menu masqué (honnête).
        $g = NavBuilder::forCompany($company, ['workforce.time_manage'], 'management');
        $this->assertNull($this->groupOf($g, 'workforce-time'), 'gestion seule sans lecture → pas de menu mort');

        // (c) Gestionnaire paie sans members.manage : voit Paie, pas Employés ni Temps.
        $g = NavBuilder::forCompany($company, ['workforce.payroll_prepare'], 'management');
        $this->assertSame('payroll', $this->groupOf($g, 'workforce-payroll'), 'paie satisfaite par payroll_prepare');
        $this->assertNull($this->groupOf($g, 'workforce-employees'));
        $this->assertNull($this->groupOf($g, 'workforce-time'));

        // (d) timesheet_view seul : voit Temps (capacité pointage lisible).
        $g = NavBuilder::forCompany($company, ['workforce.timesheet_view'], 'management');
        $this->assertSame('time', $this->groupOf($g, 'workforce-time'));

        // (e) workforce.view seul (profil consultation RH) : voit Organisation, Paramètres (et
        // « Collaborateurs » via le Core, hors scope de ce test) ; PAS Temps/Congés/Paie.
        $g = NavBuilder::forCompany($company, ['workforce.view'], 'management');
        $this->assertNull($this->groupOf($g, 'workforce-employees'), 'ADR-754 : entrée Employés retirée (→ Collaborateurs)');
        $this->assertSame('settings', $this->groupOf($g, 'workforce-organization'));
        $this->assertSame('settings', $this->groupOf($g, 'workforce-settings'));
        $this->assertNull($this->groupOf($g, 'workforce-time'));
        $this->assertNull($this->groupOf($g, 'workforce-leave'));
        $this->assertNull($this->groupOf($g, 'workforce-payroll'));
    }

    public function test_task_group_disappears_when_empty(): void
    {
        // Aucun module RH actif → aucun groupe d'activité workforce.
        $company = Company::create(['name' => 'No HR Co', 'slug' => 'nohr-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        foreach (['time', 'payroll', 'workforce', 'hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings', 'hr'] as $gk) {
            $this->assertNull($this->group($groups, $gk), "groupe {$gk} absent si aucune entrée");
        }
    }

    public function test_routes_unchanged(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        // ADR-766 : workforce-documents n'est plus un item de nav (route conservée en alias → onglet RH).
        $expectedRoutes = [
            'workforce-leave' => 'company-workforce-leave',
            'workforce-time' => 'company-workforce-time',
            'workforce-payroll' => 'company-workforce-payroll',
        ];
        foreach ($expectedRoutes as $itemKey => $route) {
            $g = $this->group($groups, self::EXPECTED_GROUP[$itemKey]);
            $item = collect($g['items'])->flatMap(fn ($i) => $i['children'] ?: [$i])->firstWhere('key', $itemKey);
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
