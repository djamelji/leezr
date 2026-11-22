<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldRuleMigrationPlanner;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\PersonFileCompleteness;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-859 (Leezr 5.0 L6) — completeness of an EMPLOYEE file with or without a login account (no account created),
 * position requirements counted, onboarding stage respected (incomplete is allowed while collecting), HR
 * confidentiality, tenant isolation, and the read-only analysis of the historical per-role rules.
 */
class Adr859PersonFileCompletenessTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private User $accessOnly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $this->company = $this->makeCompany('L6 Co');
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'access', 'name' => 'Access', 'is_administrative' => false]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', ['members.view', 'members.invite'])->pluck('id')->all());
        $this->accessOnly = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->accessOnly->id, 'role' => 'user', 'company_role_id' => $role->id]);
    }

    private function makeCompany(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'l6-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($c);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => 'workforce'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->setUpCompanyRbac($c);

        return $c;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function employee(array $attrs = [], ?Company $c = null): Employee
    {
        return Employee::create(array_merge(['company_id' => ($c ?? $this->company)->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'Ana',
            'last_name' => 'Sans Compte', 'email' => uniqid().'@co.test', 'status' => 'active', 'hire_date' => '2026-01-01'], $attrs));
    }

    /** a person field proposed by the company, optionally required for the whole company */
    private function field(string $code, bool $requiredForAll = false, ?Company $c = null): FieldDefinition
    {
        $d = FieldDefinition::firstOrCreate(['code' => $code, 'company_id' => null], ['label' => $code, 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'type' => 'string', 'validation_rules' => []]);
        FieldActivation::updateOrCreate(['company_id' => ($c ?? $this->company)->id, 'field_definition_id' => $d->id], ['enabled' => true, 'required_override' => $requiredForAll, 'order' => 1]);

        return $d;
    }

    private function requireForPosition(FieldDefinition $d, JobRole $job, ?string $stage = null): void
    {
        Requirement::create(['company_id' => $this->company->id, 'target_kind' => 'field', 'subject_scope' => Requirement::SUBJECT_PERSON, 'field_definition_id' => $d->id,
            'level' => RequirementLevel::CONFIGURABLE_REQUIRED, 'applicability' => ['job_role_ids' => [$job->id]], 'stage' => $stage,
            'source' => 'company', 'source_ref' => 'l6-test', 'status' => Requirement::STATUS_ACTIVE]);
    }

    public function test_an_employee_without_account_gets_a_file_completeness_and_a_next_action(): void
    {
        $this->field('l6_phone', true);
        $e = $this->employee();
        $users = User::count();

        $res = $this->as($this->owner)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        $row = collect($res['data'])->firstWhere('person_ref', "employee-{$e->id}");
        $this->assertNull($row['user_id']);
        $this->assertSame('file', $row['completeness']['source']);
        $this->assertFalse($row['completeness']['complete']);
        $this->assertContains('l6_phone', $row['completeness']['missing']);
        $this->assertSame('complete_file', $row['next_action']);
        $this->assertSame($users, User::count(), 'no account is created to compute a completeness');
        $this->assertGreaterThanOrEqual(1, $res['summary']['incomplete']);
    }

    public function test_data_the_hr_record_already_holds_is_not_asked_again(): void
    {
        $hire = FieldDefinition::firstOrCreate(['code' => 'hire_date', 'company_id' => null], ['label' => 'Hire', 'scope' => FieldDefinition::SCOPE_COMPANY_USER, 'type' => 'date', 'validation_rules' => []]);
        FieldActivation::updateOrCreate(['company_id' => $this->company->id, 'field_definition_id' => $hire->id], ['enabled' => true, 'required_override' => true, 'order' => 1]);
        $e = $this->employee(['hire_date' => '2026-02-01']);
        $file = PersonFileCompleteness::forEmployees($this->company, collect([$e]))[$e->id];
        $this->assertNotContains('hire_date', array_column($file['missing'], 'code'));
        $this->assertSame($file['total'], $file['filled']);
    }

    public function test_position_requirements_count_only_for_that_position(): void
    {
        $nat = $this->field('l6_nationality');
        $driver = JobRole::create(['company_id' => $this->company->id, 'title' => 'Livreur']);
        $office = JobRole::create(['company_id' => $this->company->id, 'title' => 'Bureau']);
        $this->requireForPosition($nat, $driver);
        $a = $this->employee(['job_role_id' => $driver->id]);
        $b = $this->employee(['job_role_id' => $office->id]);
        $files = PersonFileCompleteness::forEmployees($this->company, collect([$a, $b]));
        $this->assertContains('l6_nationality', array_column($files[$a->id]['missing'], 'code'));
        $this->assertNotContains('l6_nationality', array_column($files[$b->id]['missing'], 'code'));
    }

    public function test_quick_add_accepts_an_incomplete_file_and_onboarding_defers_later_items(): void
    {
        $nat = $this->field('l6_nat2');
        $job = JobRole::create(['company_id' => $this->company->id, 'title' => 'Livreur']);
        $this->requireForPosition($nat, $job, 'onboarding');

        // quick add : the position's requirements are NOT imposed at creation
        $id = $this->as($this->owner)->postJson('/api/workforce/employees', ['first_name' => 'Rapide', 'last_name' => 'Ajout', 'hire_date' => '2026-03-01', 'job_role_id' => $job->id])
            ->assertCreated()->json('id');
        $this->assertNotNull($id);

        // a recruit still being onboarded : an item due at the onboarding stage is « to collect », not « missing »
        $recruit = $this->employee(['job_role_id' => $job->id, 'status' => Employee::STATUS_INACTIVE, 'preparatory_at' => now()]);
        $file = PersonFileCompleteness::forEmployees($this->company, collect([$recruit]))[$recruit->id];
        $this->assertTrue($file['complete']);
        $this->assertSame(1, $file['to_collect']);

        // once active, the same item counts as missing
        $recruit->update(['status' => Employee::STATUS_ACTIVE, 'preparatory_at' => null]);
        $file = PersonFileCompleteness::forEmployees($this->company, collect([$recruit->fresh()]))[$recruit->id];
        $this->assertFalse($file['complete']);
        $this->assertContains('l6_nat2', array_column($file['missing'], 'code'));
    }

    public function test_file_completeness_is_an_hr_reading_and_never_crosses_companies(): void
    {
        $this->field('l6_phone', true);
        $mine = $this->employee();
        $other = $this->makeCompany('Other Co');
        $theirs = $this->employee([], $other);

        // access administration without the HR facet : no HR file figures, no missing labels
        $res = $this->as($this->accessOnly)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        foreach ($res['data'] as $r) {
            $this->assertNotSame('file', $r['completeness']['source'] ?? null);
        }
        $this->assertFalse(collect($res['data'])->contains('person_ref', "employee-{$mine->id}"));

        // tenant guard : an employee of another company is never evaluated
        $this->assertSame([], PersonFileCompleteness::forEmployees($this->company, collect([$theirs])));
        $owner = $this->as($this->owner)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        $this->assertFalse(collect($owner['data'])->contains('person_ref', "employee-{$theirs->id}"));
    }

    public function test_role_rules_are_classified_never_converted(): void
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'driver', 'name' => 'Driver', 'is_administrative' => false,
            'field_config' => [['code' => 'license', 'required' => true], ['code' => 'iban', 'visible' => false]]]);
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $job = JobRole::create(['company_id' => $this->company->id, 'title' => 'Livreur']);
        $this->employee(['user_id' => $u->id, 'job_role_id' => $job->id]);
        $requirementsBefore = Requirement::count();

        $report = collect(FieldRuleMigrationPlanner::roleRules($this->company))->firstWhere('role_key', 'driver');
        $classes = collect($report['rules'])->pluck('class', 'code')->all();
        $this->assertSame('position_candidate', $classes['license']); // all members hold ONE position : ambiguous, reported
        $this->assertSame('visibility', $classes['iban']);           // who sees a field : a software-access concern
        $this->assertSame($requirementsBefore, Requirement::count(), 'nothing is converted');
    }
}
