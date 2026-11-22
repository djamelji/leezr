<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldRuleMigrationPlanner;
use App\Core\Fields\FieldValidationService;
use App\Core\Fields\FunctionActivity;
use App\Core\Fields\MandatoryContext;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\PersonFileCompleteness;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-861 (Leezr 5.0 L7.6) — the Fleet licence is asked according to the activity really exercised, not because the
 * module is active : driver (role), office worker, manager who drives now and then (deliveries), employee WITHOUT an
 * account (position), explicit company rules preserved, historical behaviour unchanged while the switch is off.
 */
class Adr861FunctionScopedModuleFieldsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private FieldDefinition $licence;
    private User $driver;
    private User $office;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $this->company = Company::create(['name' => 'L7.6 Co', 'slug' => 'l76-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => 'workforce'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->setUpCompanyRbac($this->company);
        \App\Core\Fields\FieldDefinitionCatalog::sync();
        $this->licence = FieldDefinition::whereNull('company_id')->where('code', 'license_number')->firstOrFail();
        $this->assertContains('logistics_fleet', $this->licence->validation_rules['required_by_modules']);
        FieldActivation::updateOrCreate(['company_id' => $this->company->id, 'field_definition_id' => $this->licence->id], ['enabled' => true, 'required_override' => false, 'order' => 1]);

        $driverRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'chauffeur', 'name' => 'Chauffeur', 'is_administrative' => false, 'required_tags' => ['driving']]);
        $plainRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'bureau', 'name' => 'Bureau', 'is_administrative' => false]);
        $this->driver = $this->member($driverRole);
        $this->office = $this->member($plainRole);
        $this->manager = $this->member($plainRole);
        $this->flushCaches();
    }

    private function member(CompanyRole $role): User
    {
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function flushCaches(): void
    {
        MandatoryContext::flush();
        FunctionActivity::flush();
        \App\Core\Requirements\RequirementAuthority::flush();
    }

    private function required(User $u, ?string $roleKey): bool
    {
        $this->flushCaches();
        $f = collect(FieldResolverService::resolve($u, FieldDefinition::SCOPE_COMPANY_USER, $this->company->id, $roleKey))->firstWhere('code', 'license_number');

        return (bool) $f['required'];
    }

    private function employee(array $attrs): Employee
    {
        return Employee::create(array_merge(['company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'Ana',
            'last_name' => 'Test', 'email' => uniqid().'@co.test', 'status' => 'active', 'hire_date' => '2026-01-01'], $attrs));
    }

    private function fileMissesLicence(Employee $e, array $roleKeys = []): bool
    {
        $this->flushCaches();
        $file = PersonFileCompleteness::forEmployees($this->company, collect([$e->fresh()]), $roleKeys)[$e->id];

        return in_array('license_number', array_column($file['missing'], 'code'), true);
    }

    public function test_historical_behaviour_is_unchanged_while_the_switch_is_off(): void
    {
        $this->assertSame(FunctionActivity::SCOPE_EVERYONE, FunctionActivity::scopeOf($this->company->id));
        $this->assertTrue($this->required($this->driver, 'chauffeur'));
        $this->assertTrue($this->required($this->office, 'bureau'));
        $this->assertSame(['sometimes', 'required'], array_slice(FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $this->company->id, 'bureau')['dynamic_fields.license_number'], 0, 2));
    }

    public function test_function_scope_asks_the_licence_only_from_who_drives(): void
    {
        FunctionActivity::setScope($this->company->id, FunctionActivity::SCOPE_FUNCTION);

        $this->assertTrue($this->required($this->driver, 'chauffeur'), 'driver : role requires the driving activity');
        $this->assertFalse($this->required($this->office, 'bureau'), 'office worker : no activity, no licence');
        $this->assertFalse($this->required($this->manager, 'bureau'));

        // the manager who drives now and then : a delivery assigned → licence asked (evidence, not the access role)
        Shipment::create(['company_id' => $this->company->id, 'created_by_user_id' => $this->driver->id, 'assigned_to_user_id' => $this->manager->id,
            'reference' => 'L76-'.uniqid(), 'status' => Shipment::STATUS_PLANNED, 'origin_address' => 'A', 'destination_address' => 'B']);
        $this->assertTrue($this->required($this->manager, 'bureau'));

        // the form follows the same rule (no tags passed = person unknown → role tags only)
        $this->flushCaches();
        $this->assertContains('nullable', FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $this->company->id, 'bureau')['dynamic_fields.license_number']);
        $this->assertContains('required', FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $this->company->id, 'chauffeur')['dynamic_fields.license_number']);
    }

    public function test_an_employee_without_account_is_asked_through_the_position(): void
    {
        FunctionActivity::setScope($this->company->id, FunctionActivity::SCOPE_FUNCTION);
        $livreur = JobRole::create(['company_id' => $this->company->id, 'title' => 'Livreur']);
        $bureau = JobRole::create(['company_id' => $this->company->id, 'title' => 'Bureau']);
        $courier = $this->employee(['job_role_id' => $livreur->id]);
        $clerk = $this->employee(['job_role_id' => $bureau->id]);

        $this->assertFalse($this->fileMissesLicence($courier), 'nothing declared yet : not asked');

        // the company declares the activity on the position (API, same permission as editing the position)
        $owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson("/api/workforce/job-roles/{$livreur->id}", ['activities' => ['driving']])->assertOk();
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson("/api/workforce/job-roles/{$livreur->id}", ['activities' => ['flying']])->assertStatus(422);

        $this->assertTrue($this->fileMissesLicence($courier), 'courier without account : asked through the position');
        $this->assertFalse($this->fileMissesLicence($clerk));
    }

    public function test_explicit_company_rules_are_preserved(): void
    {
        FunctionActivity::setScope($this->company->id, FunctionActivity::SCOPE_FUNCTION);
        FieldActivation::where('company_id', $this->company->id)->where('field_definition_id', $this->licence->id)->update(['required_override' => true]);
        $this->assertTrue($this->required($this->office, 'bureau'), '« required for the whole company » stays for everyone');

        FieldActivation::where('company_id', $this->company->id)->where('field_definition_id', $this->licence->id)->update(['required_override' => false]);
        CompanyRole::where('company_id', $this->company->id)->where('key', 'bureau')->update(['field_config' => json_encode([['code' => 'license_number', 'visible' => true, 'required' => true]])]);
        $this->assertTrue($this->required($this->office, 'bureau'), 'a per-role rule set by the company stays');
    }

    public function test_position_authority_never_uses_the_access_role(): void
    {
        \Illuminate\Support\Facades\DB::table('company_document_settings')->updateOrInsert(['company_id' => $this->company->id], ['requirements_authority' => 'position']);
        FunctionActivity::setScope($this->company->id, FunctionActivity::SCOPE_FUNCTION);
        $this->assertFalse($this->required($this->driver, 'chauffeur'), 'position authority : the access role alone never requires');
    }

    public function test_simulation_only_narrows_and_the_switch_writes_only_on_a_clone(): void
    {
        $livreur = JobRole::create(['company_id' => $this->company->id, 'title' => 'Livreur', 'metadata' => ['activities' => ['driving']]]);
        $this->employee(['job_role_id' => $livreur->id]);
        $this->employee(['user_id' => $this->office->id]);
        $this->employee(['user_id' => $this->driver->id]);

        $row = collect(FieldRuleMigrationPlanner::moduleWideObligations($this->company))->firstWhere('code', 'license_number');
        $this->assertSame(3, $row['everyone']);
        $this->assertSame(2, $row['by_function']);
        $this->assertSame(1, $row['kept_by']['position']);
        $this->assertSame(1, $row['kept_by']['role']);
        $this->assertSame(0, $row['newly_required']);
        $this->assertSame(FunctionActivity::SCOPE_EVERYONE, FunctionActivity::scopeOf($this->company->id), 'simulation writes nothing');

        $this->artisan('fields:rules-audit', ['--function-scope' => 'on', '--company' => [$this->company->id]])->assertFailed();
        $this->assertSame(FunctionActivity::SCOPE_EVERYONE, FunctionActivity::scopeOf($this->company->id));
    }
}
