<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Navigation\NavBuilder;
use App\Core\Workforce\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-692 (WORKFORCE EXPERIENCE 2026 / E3-A) — INDÉPENDANCE SECTORIELLE.
 *
 * Workforce est une famille de modules HORIZONTAUX réutilisables par plusieurs JobDomains. Ce test
 * prouve, avec une fixture JobDomain NON-logistique (sans créer de fausse prise en charge métier),
 * que les mêmes composants/endpoints génériques fonctionnent :
 *   - l'annuaire répond identiquement quel que soit le JobDomain ;
 *   - Workforce fonctionne même quand le module Logistics est ABSENT ;
 *   - la navigation Workforce est identique d'un JobDomain à l'autre ;
 *   - aucune fuite de données entre tenants ;
 *   - les permissions restent indépendantes du secteur.
 *
 * Non-régression : aucun `if (jobdomain === 'logistics')` ne doit casser un vertical non-logistique.
 */
class WorkforceMultisectorIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private const WF_MODULES = ['workforce', 'workforce_leave', 'workforce_planning', 'workforce_documents'];

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        // Modules plateforme : les modules Workforce + un module logistique (pour prouver qu'il reste FACULTATIF).
        foreach ([...self::WF_MODULES, 'logistics_shipments'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        // JobDomain logistique (réel) + fixture « restauration » NON-logistique (config valide, aucune
        // prise en charge métier codée — juste une clé de données + modules Workforce, SANS logistics).
        Jobdomain::updateOrCreate(['key' => 'logistique'], ['label' => 'Logistique', 'default_modules' => [...self::WF_MODULES, 'logistics_shipments']]);
        Jobdomain::updateOrCreate(['key' => 'restauration'], ['label' => 'Restauration', 'default_modules' => self::WF_MODULES]);
    }

    /**
     * Crée une company d'un JobDomain donné, avec les modules fournis, un user RH (workforce.view/manage/
     * compensation_read) et un employé. Retourne [company, user, employee].
     */
    private function makeCompany(string $jobdomain, array $modules, string $firstName): array
    {
        $company = Company::create(['name' => "Co $jobdomain ".uniqid(), 'slug' => 'co-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => $jobdomain, 'market_key' => 'FR']);
        foreach ($modules as $m) {
            CompanyModuleActivationReason::create(['company_id' => $company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $company->id, 'module_key' => $m, 'is_enabled_for_company' => true]);
        }
        ModuleRegistry::clearCache();

        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'rh-'.uniqid(), 'name' => 'RH', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage', 'workforce.compensation_read'])->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'user', 'company_role_id' => $role->id]);

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'first_name' => $firstName, 'last_name' => 'Doe',
            'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);

        return [$company, $user, $employee];
    }

    private function list(User $u, Company $c)
    {
        return $this->actingAs($u)->getJson('/api/workforce/employees', ['X-Company-Id' => $c->id]);
    }

    public function test_employee_directory_works_without_logistics_module(): void
    {
        // Restauration : modules Workforce SEULEMENT, PAS de logistics_shipments.
        [$resto, $rhResto, $emp] = $this->makeCompany('restauration', self::WF_MODULES, 'Chef');

        $this->assertFalse(\App\Core\Modules\ModuleGate::isActive($resto, 'logistics_shipments'), 'logistics doit être ABSENT');

        $res = $this->list($rhResto, $resto);
        $res->assertOk();
        $this->assertSame('Chef', $res->json('data.0.first_name'), 'l\'annuaire fonctionne sans Logistics');
    }

    public function test_directory_shape_is_identical_across_jobdomains(): void
    {
        [$logi, $rhLogi] = $this->makeCompany('logistique', [...self::WF_MODULES, 'logistics_shipments'], 'Driver');
        [$resto, $rhResto] = $this->makeCompany('restauration', self::WF_MODULES, 'Waiter');

        $a = $this->list($rhLogi, $logi)->assertOk()->json();
        $b = $this->list($rhResto, $resto)->assertOk()->json();

        // Mêmes composants génériques → même forme de réponse (enveloppe + champs d'item).
        $this->assertSame(array_keys($a), array_keys($b), 'enveloppe identique quel que soit le JobDomain');
        $this->assertSame(array_keys($a['data'][0]), array_keys($b['data'][0]), 'champs d\'employé identiques');
    }

    public function test_workforce_nav_is_identical_across_jobdomains(): void
    {
        [$logi] = $this->makeCompany('logistique', [...self::WF_MODULES, 'logistics_shipments'], 'Driver');
        [$resto] = $this->makeCompany('restauration', self::WF_MODULES, 'Waiter');

        $wfItems = function (Company $c) {
            $groups = NavBuilder::forCompany($c, null, 'management');
            $g = collect($groups)->firstWhere('key', 'workforce');

            return $g ? array_column($g['items'], 'key') : [];
        };

        $itemsLogi = $wfItems($logi);
        $itemsResto = $wfItems($resto);
        sort($itemsLogi);
        sort($itemsResto);

        $this->assertNotEmpty($itemsResto, 'le groupe Workforce existe pour un JobDomain non-logistique');
        $this->assertSame($itemsLogi, $itemsResto, 'navigation Workforce identique entre JobDomains');
    }

    public function test_no_cross_tenant_leak_between_jobdomains(): void
    {
        [$logi, $rhLogi, $empLogi] = $this->makeCompany('logistique', [...self::WF_MODULES, 'logistics_shipments'], 'Driver');
        [$resto, $rhResto, $empResto] = $this->makeCompany('restauration', self::WF_MODULES, 'Waiter');

        // La RH restauration ne voit QUE ses employés.
        $data = $this->list($rhResto, $resto)->assertOk()->json('data');
        $ids = array_column($data, 'id');
        $this->assertContains($empResto->id, $ids);
        $this->assertNotContains($empLogi->id, $ids, 'aucun employé d\'un autre tenant');

        // Accès direct à l'employé de l'autre tenant (même avec son propre header) : refusé.
        $this->actingAs($rhResto)->getJson("/api/workforce/employees/{$empLogi->id}", ['X-Company-Id' => $resto->id])
            ->assertNotFound();

        // Tenter d'emprunter le header de l'autre company sans y être membre : refusé par SetCompanyContext.
        $this->actingAs($rhResto)->getJson('/api/workforce/employees', ['X-Company-Id' => $logi->id])
            ->assertForbidden();
    }

    public function test_permissions_are_sector_independent(): void
    {
        // Même permission (workforce.view) → même accès, quel que soit le JobDomain. Un rôle « chef de
        // salle » (restauration) délégué = exactement les mêmes clés RBAC qu'un « chef d'équipe » logistique.
        [$resto, , ] = $this->makeCompany('restauration', self::WF_MODULES, 'Waiter');

        $viewer = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $resto->id, 'key' => 'chef-salle', 'name' => 'Chef de salle', 'is_system' => false, 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view'])->pluck('id')->toArray());
        Membership::create(['user_id' => $viewer->id, 'company_id' => $resto->id, 'role' => 'user', 'company_role_id' => $role->id]);

        $this->actingAs($viewer)->getJson('/api/workforce/employees', ['X-Company-Id' => $resto->id])->assertOk();
        // Sans la permission paie : pas d'accès à la paie (indépendant du secteur).
        $this->actingAs($viewer)->getJson('/api/workforce/payroll', ['X-Company-Id' => $resto->id])->assertForbidden();
    }
}
