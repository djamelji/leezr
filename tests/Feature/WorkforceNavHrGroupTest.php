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
 * ADR-635 → W-HR-UX-7 : les entrées Workforce sont regroupées par TÂCHE (sections visibles, pas de
 * sous-menus repliables), de façon 100 % manifest-driven : `group` déclaré dans les manifests,
 * ordre/i18n dans NavGroupRegistry. Respect des permissions, des modules actifs, pas de doublon,
 * groupes élagués si vides, routes inchangées. « Mon espace » rejoint le groupe personnel `account`.
 */
class WorkforceNavHrGroupTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFORCE_MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    /** Mapping attendu item → groupe par tâche. */
    private const EXPECTED_GROUP = [
        'workforce-employees' => 'hr_team',
        'workforce-time' => 'hr_time',
        'workforce-planning' => 'hr_time',
        'workforce-leave' => 'hr_absences',
        'workforce-payroll' => 'hr_payroll',
        'workforce-dsn' => 'hr_payroll',
        'workforce-documents' => 'hr_documents',
        'workforce-contract-templates' => 'hr_documents',
        'workforce-signature' => 'hr_documents',
        'workforce-organization' => 'hr_settings',
        'workforce-settings' => 'hr_settings',
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

    public function test_workforce_items_land_in_their_task_group(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        foreach (self::EXPECTED_GROUP as $item => $expectedGroup) {
            $this->assertSame($expectedGroup, $this->groupOf($groups, $item), "{$item} doit être dans {$expectedGroup}");
        }

        // Les titres de section sont des clés i18n (pas des chaînes en dur).
        foreach (['hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings'] as $gk) {
            $this->assertSame("nav.groups.{$gk}", $this->group($groups, $gk)['titleKey']);
        }

        // Plus aucune entrée RH ne tombe dans le fallback historique `hr`.
        $this->assertNull($this->group($groups, 'hr'), 'le fallback hr est élagué (plus aucun item)');
    }

    public function test_my_space_is_in_personal_account_group(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertSame('account', $this->groupOf($groups, 'workforce-me'), 'Mon espace = groupe personnel');
        $this->assertNotContains('workforce-me', $this->itemKeys($groups, 'hr_team'), 'Mon espace n\'est pas mêlé au RH management');
    }

    public function test_inactive_module_does_not_contribute(): void
    {
        // Planning NON activé → son entrée n'apparaît pas dans hr_time (seul le pointage y reste).
        $company = $this->companyWith(['workforce', 'workforce_leave']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        $this->assertSame('hr_absences', $this->groupOf($groups, 'workforce-leave'));
        $this->assertNull($this->groupOf($groups, 'workforce-planning'), 'module planning inactif → pas d\'entrée');
    }

    public function test_permissions_filter_hr_entries(): void
    {
        $company = $this->companyWith(self::WORKFORCE_MODULES);
        // Non-bypass avec uniquement leave_request : voit congés + "me" (sans permission), pas employés.
        $groups = NavBuilder::forCompany($company, ['workforce.leave_request'], 'management');

        $this->assertSame('hr_absences', $this->groupOf($groups, 'workforce-leave'));
        $this->assertSame('account', $this->groupOf($groups, 'workforce-me'), 'entrée sans permission visible');
        $this->assertNull($this->groupOf($groups, 'workforce-employees'), 'employés nécessite workforce.view');
    }

    public function test_task_groups_disappear_when_empty(): void
    {
        // Aucun module RH actif → aucun groupe par tâche.
        $company = Company::create(['name' => 'No HR Co', 'slug' => 'nohr-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);
        $groups = NavBuilder::forCompany($company, null, 'management');

        foreach (['hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings', 'hr'] as $gk) {
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
            'workforce-planning' => 'company-workforce-planning',
            'workforce-dsn' => 'company-workforce-dsn',
            'workforce-signature' => 'company-workforce-signature',
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

    public function test_task_group_labels_exist_in_all_locales(): void
    {
        foreach (['fr', 'en', 'ar'] as $locale) {
            $json = json_decode(file_get_contents(base_path("resources/js/plugins/i18n/locales/{$locale}.json")), true);
            foreach (['hr_team', 'hr_time', 'hr_absences', 'hr_payroll', 'hr_documents', 'hr_settings'] as $gk) {
                $this->assertNotEmpty($json['nav']['groups'][$gk] ?? null, "label nav.groups.{$gk} présent en {$locale}");
            }
        }
    }
}
