<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementParityComparator;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Security\WorkforceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-817 (L1) — the POSITION carries professional requirements, independently of the RBAC role :
 *  - per-position takeover proposal of the historical role rules (origin, contradictions, current state),
 *    read-only, adopted only explicitly through the existing save ; operational resolution unchanged ;
 *  - D4 : `workforce.view_all` = read every file without modification ; no existing role widened ;
 *  - D17 : a terminated employee cannot become a manager ; the current manager stays accepted ;
 *  - tenant isolation, accountless people, historical data untouched.
 */
class Adr817PositionRequirementsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $owner;
    private JobRole $driverJob;
    private CompanyRole $driverRole;
    private CompanyRole $officeRole;
    private array $emp = [];
    private int $testAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);

        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $admin = $this->setUpCompanyRbac($this->a);
        $this->testAdminRoleId = $admin->id; // test helper : « all permissions » administrator
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $admin->id]);

        // Historical role rules : the driver ROLE demands a driving licence and a phone ; the office role does not.
        $this->driverRole = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'drv', 'name' => 'Chauffeur', 'is_administrative' => false,
            'doc_config' => [['code' => 'driving_license', 'visible' => true, 'required' => true, 'order' => 1], ['code' => 'rib', 'visible' => false]],
            'field_config' => [['code' => 'phone', 'visible' => true, 'required' => true]]]);
        $this->officeRole = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'off', 'name' => 'Bureau', 'is_administrative' => false]);

        $this->driverJob = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'company']);
        $this->emp['driver'] = $this->employee($this->driverJob, $this->member($this->driverRole));
        $this->emp['driver2'] = $this->employee($this->driverJob, $this->member($this->driverRole));
        $this->emp['office'] = $this->employee($this->driverJob, $this->member($this->officeRole)); // même poste, autre rôle
        $this->emp['accountless'] = $this->employee($this->driverJob);
        $this->emp['owner'] = $this->employee(null, $this->owner); // propriétaire salarié, sans poste (historique)
        $this->emp['left'] = $this->employee(null, null, 'terminated');
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => 'workforce'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => $t->code === 'id_card']);
        }

        return $c;
    }

    private function member(CompanyRole $role): User
    {
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $role->company_id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function withPerms(array $perms, bool $admin = true, ?Company $c = null): User
    {
        $c ??= $this->a;
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());

        return $this->member($role);
    }

    private function employee(?JobRole $job, ?User $user = null, string $status = 'active'): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $user?->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => $status, 'hire_date' => '2026-01-01', 'job_role_id' => $job?->id]);
    }

    private function as(User $u, ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id]);
    }

    private function proposal(?User $u = null)
    {
        return $this->as($u ?? $this->owner)->getJson('/api/workforce/requirements/proposal?scope=job_role:'.$this->driverJob->id);
    }

    private function snapshot(): array
    {
        return [Requirement::count(), DocumentRequest::withoutGlobalScopes()->count(), CompanyRole::orderBy('id')->get(['doc_config', 'field_config', 'required_tags'])->toJson()];
    }

    public function test_takeover_proposal_shows_role_rules_with_origin_and_contradictions_without_writing(): void
    {
        $before = $this->snapshot();
        $res = $this->proposal()->assertOk()->json();
        $this->assertSame($before, $this->snapshot(), 'the proposal is read-only');

        $this->assertSame(['total' => 4, 'with_account' => 3, 'accountless' => 1, 'without_role' => 0], $res['people']);
        $items = collect($res['items'])->keyBy(fn ($i) => $i['kind'].':'.$i['code']);
        $licence = $items['document:driving_license'];
        $this->assertSame('partial', $licence['status'], 'required by the driver role only : contradiction inside the position');
        $this->assertSame([['role' => 'Chauffeur', 'people' => 2, 'rule' => 'role_config']], $licence['origins']);
        $this->assertSame([['role' => 'Bureau', 'people' => 1]], $licence['not_from']);
        $this->assertSame('partial', $items['field:phone']['status']);
        // id_card is required for everyone (company activation) : not a position matter, not proposed.
        $this->assertArrayNotHasKey('document:id_card', $items->all());
        // role visibility (rib hidden for drivers) is an access rule : reported, never proposed.
        $this->assertArrayNotHasKey('document:rib', $items->all());
        $this->assertSame([], collect($res['hidden_by_roles'])->where('code', 'rib')->values()->all(), 'rib is not required for everyone, nothing hidden');
        $this->assertArrayNotHasKey('role_id', $licence['origins'][0], 'no internal id exposed');
    }

    public function test_explicit_adoption_configures_the_position_and_leaves_operational_resolution_unchanged(): void
    {
        $opBefore = DocumentResolverService::requirementMap($this->a->id, 'off', 'FR', DocumentType::SCOPE_COMPANY_USER);
        $requestsBefore = DocumentRequest::withoutGlobalScopes()->count();

        $this->as($this->owner)->postJson('/api/workforce/requirements', ['scope' => 'job_role:'.$this->driverJob->id,
            'items' => [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required']]])->assertOk();

        $items = collect($this->proposal()->assertOk()->json('items'))->keyBy(fn ($i) => $i['kind'].':'.$i['code']);
        $this->assertSame('configured', $items['document:driving_license']['status']);
        $this->assertSame('required', $items['document:driving_license']['current_state']);
        $this->assertSame('partial', $items['field:phone']['status'], 'not adopted : unchanged');

        // Historical (role-based) operational resolution and existing requests are untouched (no global activation).
        $this->assertEquals(array_map(fn ($e) => [$e['required'], $e['visible']], $opBefore),
            array_map(fn ($e) => [$e['required'], $e['visible']], DocumentResolverService::requirementMap($this->a->id, 'off', 'FR', DocumentType::SCOPE_COMPANY_USER)));
        $this->assertSame($requestsBefore, DocumentRequest::withoutGlobalScopes()->count());

        // Parity with the registry is measurable : the office-role person of the position now differs (licence).
        $report = RequirementParityComparator::compareWithRegistry($this->a);
        $this->assertGreaterThan(0, $report['differences']);
        $this->assertSame(0, $report['by_category']['resolution_bug'], 'position-driven differences are explained, never « unknown cause »');
    }

    public function test_proposal_is_reserved_to_requirement_configuration_and_isolated_per_company(): void
    {
        $this->proposal($this->withPerms(['workforce.view', 'workforce.manage']))->assertForbidden();
        $this->proposal($this->withPerms(['workforce.view', 'documents.configure']))->assertOk();

        $other = User::factory()->create();
        Membership::create(['user_id' => $other->id, 'company_id' => $this->b->id, 'role' => 'owner']);
        $this->proposal($other)->assertForbidden(); // not a member of A
        $this->as($other, $this->b)->getJson('/api/workforce/requirements/proposal?scope=job_role:'.$this->driverJob->id)->assertStatus(422); // A's position unknown in B
        $this->as($this->owner)->getJson('/api/workforce/requirements/proposal?scope=all')->assertStatus(422);
    }

    public function test_view_all_reads_every_file_without_modification_rights(): void
    {
        $reader = $this->withPerms(['workforce.view', 'workforce.view_all']);
        $team = $this->withPerms(['workforce.view']);

        $ids = collect($this->as($reader)->getJson('/api/workforce/employees?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->emp['driver']->id) && $ids->contains($this->emp['accountless']->id), 'reads everyone, accountless included');
        $this->as($reader)->getJson('/api/workforce/employees/'.$this->emp['owner']->id)->assertOk();
        $this->as($reader)->putJson('/api/workforce/employees/'.$this->emp['driver']->id, ['first_name' => 'X'])->assertForbidden();

        $teamIds = collect($this->as($team)->getJson('/api/workforce/employees?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($teamIds->contains($this->emp['driver']->id), 'view alone stays scoped (self + reports)');

        // Planning write scope is NOT widened by view_all.
        $this->assertFalse(WorkforceAccess::viewAllWith(fn ($p) => in_array($p, ['workforce.view', 'workforce.view_all'], true), 'planning'));
        $this->assertTrue(WorkforceAccess::viewAllWith(fn ($p) => in_array($p, ['workforce.view', 'workforce.view_all'], true), 'employee'));
    }

    public function test_view_all_is_never_granted_implicitly(): void
    {
        $this->assertNotNull(CompanyPermission::where('key', 'workforce.view_all')->first());
        $bundles = collect(\App\Core\Modules\ModuleRegistry::definitions()['workforce']->bundles ?? [])->keyBy('key');
        $holders = $bundles->filter(fn ($b) => in_array('workforce.view_all', $b['permissions'], true))->keys()->all();
        $this->assertSame(['workforce.hr_consultation'], $holders, 'only the new explicit bundle carries it');
        // No role receives it unless explicitly given (the test helper's all-permissions admin aside).
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('company_role_permission')->where('company_role_id', '!=', $this->testAdminRoleId)
            ->where('company_permission_id', CompanyPermission::where('key', 'workforce.view_all')->value('id'))->count());
    }

    public function test_manager_must_be_a_current_employee_but_history_is_kept(): void
    {
        $this->as($this->owner)->putJson('/api/workforce/employees/'.$this->emp['driver']->id, ['manager_id' => $this->emp['left']->id])->assertStatus(422);
        $this->as($this->owner)->putJson('/api/workforce/employees/'.$this->emp['driver']->id, ['manager_id' => $this->emp['office']->id])->assertOk();

        // An employee whose manager has since left can still be edited (manager unchanged).
        Employee::withoutGlobalScopes()->whereKey($this->emp['driver2']->id)->update(['manager_id' => $this->emp['left']->id]);
        $this->as($this->owner)->putJson('/api/workforce/employees/'.$this->emp['driver2']->id, ['first_name' => 'Y', 'manager_id' => $this->emp['left']->id])->assertOk();
    }

    public function test_position_sheet_data_people_per_position_and_company_isolated_validation(): void
    {
        $ids = collect($this->as($this->owner)->getJson('/api/workforce/employees?per_page=100&job_role_id='.$this->driverJob->id)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$this->emp['driver'], $this->emp['driver2'], $this->emp['office'], $this->emp['accountless']])->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $ids);

        $foreignDept = \App\Core\Workforce\Department::withoutGlobalScopes()->create(['company_id' => $this->b->id, 'name' => 'Ailleurs']);
        $this->as($this->owner)->putJson('/api/workforce/job-roles/'.$this->driverJob->id, ['department_id' => $foreignDept->id])->assertStatus(422);
        $this->as($this->owner)->putJson('/api/workforce/job-roles/'.$this->driverJob->id, ['description' => 'Livraisons urbaines'])->assertOk();
        $this->assertSame('Livraisons urbaines', $this->driverJob->fresh()->description);
    }
}
