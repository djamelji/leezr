<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldObligationSources;
use App\Core\Fields\FieldRuleMigrationPlanner;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-858 (Leezr 5.0 L5) — People list synthesis (real figures, never beyond the actor's facets), filter counts,
 * next action ; explicit origin of every person-field obligation ; audit + reversible migration of the rules
 * stored by the retired « Outils → Champs » drawer (only redundant rules change, behaviour proved identical).
 */
class Adr858PeopleAndFieldRulesTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private User $hrOnly;
    private CompanyRole $driverRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $this->company = Company::create(['name' => 'L5 Co', 'slug' => 'l5-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => 'workforce'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $hr = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'hronly', 'name' => 'HR', 'is_administrative' => false]);
        $hr->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage'])->pluck('id')->all());
        $this->hrOnly = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->hrOnly->id, 'role' => 'user', 'company_role_id' => $hr->id]);
        $this->driverRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'driver', 'name' => 'Driver', 'is_administrative' => false]);
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge(['company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'Jean',
            'last_name' => 'Dupont', 'email' => uniqid().'@co.test', 'status' => 'active', 'hire_date' => '2026-01-01'], $attrs));
    }

    private function field(string $code): FieldDefinition
    {
        return FieldDefinition::firstOrCreate(['code' => $code, 'company_id' => null], ['label' => $code, 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'type' => 'string', 'validation_rules' => []]);
    }

    public function test_people_list_carries_a_synthesis_filter_counts_and_next_actions(): void
    {
        $this->employee(['first_name' => 'Ana', 'status' => 'active']);
        $this->employee(['first_name' => 'Bea', 'status' => 'active']);
        $res = $this->as($this->owner)->getJson('/api/company/people?per_page=100')->assertOk()->json();

        $this->assertSame($res['total'], $res['summary']['total']);
        $this->assertGreaterThanOrEqual(2, $res['summary']['active']);
        $this->assertIsInt($res['summary']['invitations']);
        $this->assertGreaterThanOrEqual(2, $res['filter_counts']['employment_status']['active']);
        $this->assertArrayHasKey('next_action', $res['data'][0]);

        // the « to act » filter returns exactly the rows that have a next action
        $toAct = $this->as($this->owner)->getJson('/api/company/people?per_page=100&attention=to_act')->assertOk()->json();
        $this->assertSame($res['summary']['to_act'], $toAct['total']);
        foreach ($toAct['data'] as $r) {
            $this->assertNotNull($r['next_action']);
        }
    }

    public function test_figures_the_actor_may_not_read_are_null_never_zero(): void
    {
        $this->employee();
        $res = $this->as($this->hrOnly)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        // an HR profile without access administration : no invitation figure, no account counts ; since ADR-859 it reads
        // the HR FILE completeness (labels and counts), never the account profile completeness
        $this->assertNull($res['summary']['invitations']);
        $this->assertIsInt($res['summary']['incomplete']);
        $this->assertNull($res['filter_counts']['access_state']);
        foreach ($res['data'] as $r) {
            if ($r['completeness'] ?? null) {
                $this->assertSame('file', $r['completeness']['source']);
            }
        }
    }

    public function test_job_role_filter(): void
    {
        $job = \App\Core\Workforce\JobRole::create(['company_id' => $this->company->id, 'title' => 'Livreur']);
        $this->employee(['first_name' => 'Ana', 'job_role_id' => $job->id]);
        $this->employee(['first_name' => 'Bea']);
        $res = $this->as($this->owner)->getJson('/api/company/people?per_page=100&job_role=Livreur')->assertOk()->json();
        $this->assertSame(1, $res['total']);
        $this->assertSame(1, $res['filter_counts']['job_role']['Livreur']);
    }

    public function test_catalog_explains_where_each_obligation_comes_from(): void
    {
        $nat = $this->field('nationality');
        FieldActivation::create(['company_id' => $this->company->id, 'field_definition_id' => $nat->id, 'enabled' => true, 'required_override' => true, 'order' => 1]);
        $this->driverRole->update(['field_config' => [['code' => 'nationality', 'visible' => false]]]);
        $o = FieldObligationSources::forCompany($this->company)['nationality'];
        $this->assertTrue($o['company']);
        $this->assertSame(['Driver'], $o['hidden_for_roles']);

        $cat = $this->as($this->owner)->getJson('/api/company/catalogs')->assertOk()->json();
        $row = collect($cat['fields'])->firstWhere('code', 'nationality');
        $this->assertTrue($row['obligation']['company']);
        $this->assertContains($cat['requirements_authority'], ['role', 'position']);
    }

    public function test_migration_changes_only_redundant_rules_and_rolls_back_exactly(): void
    {
        $hire = $this->field('hire_date_l5');
        $hire->update(['validation_rules' => ['required' => true]]); // required everywhere by the Platform catalogue
        $nat = $this->field('nationality_l5');
        $city = $this->field('birth_city_l5');
        foreach ([[$hire, true, true], [$nat, true, true], [$city, false, true]] as [$d, $en, $req]) {
            FieldActivation::create(['company_id' => $this->company->id, 'field_definition_id' => $d->id, 'enabled' => $en, 'required_override' => $req, 'order' => 1]);
        }
        $this->driverRole->update(['field_config' => [['code' => 'hire_date_l5', 'visible' => true, 'required' => true], ['code' => 'nationality_l5', 'visible' => true]]]);
        $driver = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $driver->id, 'role' => 'user', 'company_role_id' => $this->driverRole->id]);

        $plan = FieldRuleMigrationPlanner::plan($this->company);
        $classes = collect($plan['findings'])->map(fn ($f) => $f['code'].':'.$f['class'])->all();
        $this->assertContains('hire_date_l5:company_redundant', $classes);
        $this->assertContains('hire_date_l5:role_redundant', $classes);
        $this->assertContains('nationality_l5:company_global', $classes);   // a genuine company-wide rule is kept
        $this->assertContains('birth_city_l5:company_dormant', $classes);   // ambiguous : reported, never touched

        // dry run : compared, nothing written
        $dry = FieldRuleMigrationPlanner::apply($this->company, true);
        $this->assertSame(2, $dry['changed']);
        $this->assertTrue($dry['identical']);
        $this->assertTrue((bool) FieldActivation::where('field_definition_id', $hire->id)->value('required_override'));

        // apply : only the redundant rules change ; every account keeps the same required / missing fields
        $run = FieldRuleMigrationPlanner::apply($this->company, false);
        $this->assertTrue($run['applied']);
        $this->assertSame($run['before'], $run['after']);
        $this->assertFalse((bool) FieldActivation::where('field_definition_id', $hire->id)->value('required_override'));
        $this->assertTrue((bool) FieldActivation::where('field_definition_id', $nat->id)->value('required_override'));
        $this->assertTrue((bool) FieldActivation::where('field_definition_id', $city->id)->value('required_override'));

        // rollback restores exactly
        FieldRuleMigrationPlanner::rollback($run['backup']);
        $this->assertTrue((bool) FieldActivation::where('field_definition_id', $hire->id)->value('required_override'));
        $this->assertTrue((bool) collect($this->driverRole->fresh()->field_config)->firstWhere('code', 'hire_date_l5')['required']);
        $this->assertSame($plan['findings'], FieldRuleMigrationPlanner::plan($this->company)['findings']);
    }

    public function test_the_migration_command_never_writes_outside_a_marked_clone(): void
    {
        $this->artisan('fields:rules-audit', ['--apply' => true, '--company' => [$this->company->id]])->assertFailed();
        $this->artisan('fields:rules-audit', ['--company' => [$this->company->id]])->assertSuccessful();
    }
}
