<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementAuthority;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-819 (Company 2.0) — catalogues (origin, availability ≠ obligation, where used), minimal business base,
 * position requirements with a collection moment, and the EXPLICIT application to people already in place.
 */
class Adr819CatalogsPositionsTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules, SetsUpCompanyRbac;

    private Company $a;
    private Company $b;
    private User $owner;
    private User $hr;
    private JobRole $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce'], 'allow_custom_fields' => true]);
        $this->a = $this->company('a');
        $this->b = $this->company('b');
        $admin = $this->setUpCompanyRbac($this->a);
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'hr', 'name' => 'RH', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage', 'documents.view', 'documents.manage', 'documents.configure'])->pluck('id')->all());
        $this->hr = User::factory()->create();
        Membership::create(['user_id' => $this->hr->id, 'company_id' => $this->a->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $this->driver = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'company']);
    }

    private function company(string $n): Company
    {
        $c = Company::create(['name' => "Soc {$n}", 'slug' => 'adr819c-'.$n, 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => 'workforce'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => false]);
        }

        return $c;
    }

    private function api(User $u, string $method, string $uri, array $body = [])
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->a->id, 'X-Locale' => 'fr'])->json($method, '/api'.$uri, $body);
    }

    private function employee(string $n, ?JobRole $job = null, array $over = []): Employee
    {
        return Employee::withoutGlobalScopes()->create(array_merge(['company_id' => $this->a->id, 'first_name' => 'E', 'last_name' => $n, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $n, 'email' => strtolower($n).'@adr819.test', 'job_role_id' => ($job ?? $this->driver)->id], $over));
    }

    public function test_minimal_business_base_activates_few_fields_and_recommends_the_rest(): void
    {
        $c = Company::create(['name' => 'Neuve', 'slug' => 'adr819-new', 'market_key' => 'FR', 'plan_key' => 'starter']);
        JobdomainGate::assignToCompany($c, 'logistique');
        $active = FieldActivation::where('company_id', $c->id)->where('enabled', true)->with('definition:id,code,scope')->get()
            ->filter(fn ($a) => $a->definition?->scope === FieldDefinition::SCOPE_COMPANY_USER)->pluck('definition.code')->all();
        $this->assertContains('hire_date', $active);
        $this->assertNotContains('geographic_zone', $active, 'recommended fields are not activated');
        $this->assertNotContains('social_security_number', $active);
        $this->assertLessThanOrEqual(10, count($active));
        $this->assertFalse(DocumentTypeActivation::where('company_id', $c->id)->whereHas('documentType', fn ($q) => $q->where('code', 'medical_certificate'))->exists());

        $catalog = \App\Core\Catalogs\CompanyCatalogReadModel::forCompany($c);
        $zone = collect($catalog['fields'])->firstWhere('code', 'geographic_zone');
        $this->assertSame('recommended', $zone['status']);
        $this->assertContains(['kind' => 'jobdomain', 'recommended' => true], $zone['origins']);
    }

    public function test_catalog_shows_origin_usage_and_custom_items_without_making_anything_mandatory(): void
    {
        $this->api($this->owner, 'POST', '/company/field-definitions', ['code' => 'permis_caces', 'label' => 'CACES', 'scope' => 'company_user', 'type' => 'string'])->assertOk();
        $res = $this->api($this->hr, 'GET', '/company/catalogs')->assertOk();
        $caces = collect($res->json('fields'))->firstWhere('code', 'permis_caces');
        $this->assertSame([['kind' => 'custom']], $caces['origins']);
        $this->assertSame('active', $caces['status']);
        $this->assertSame([], $caces['used_by']['positions'], 'available ≠ required');
        $contract = collect($res->json('documents'))->firstWhere('code', 'work_contract');
        $this->assertContains('module_proof', $contract['sources']);
        $hire = collect($res->json('fields'))->firstWhere('code', 'hire_date');
        $this->assertTrue(collect($hire['origins'])->contains(fn ($o) => $o['kind'] === 'module' && $o['module'] === 'workforce'));

        // Require it for a position, with a collection moment → visible where used.
        $this->api($this->hr, 'POST', '/workforce/requirements', ['scope' => 'job_role:'.$this->driver->id, 'items' => [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required', 'stage' => 'hire']]])->assertOk();
        $doc = collect($this->api($this->hr, 'GET', '/company/catalogs')->json('documents'))->firstWhere('code', 'driving_license');
        $this->assertSame([['id' => $this->driver->id, 'title' => 'Livreur']], $doc['used_by']['positions']);
        $this->assertSame('hire', Requirement::where('company_id', $this->a->id)->where('status', 'active')->whereHas('documentType', fn ($q) => $q->where('code', 'driving_license'))->value('stage'));
        $state = $this->actingAs($this->hr)->withHeaders(['X-Company-Id' => $this->a->id])->getJson('/api/workforce/requirements?scope=job_role:'.$this->driver->id)->assertOk();
        $this->assertSame('hire', collect($state->json('state.documents'))->firstWhere('code', 'driving_license')['stage']);

        // Changing only the moment is a real change (new version, history kept).
        $this->api($this->hr, 'POST', '/workforce/requirements', ['scope' => 'job_role:'.$this->driver->id, 'items' => [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required', 'stage' => 'onboarding']]])->assertOk();
        $this->assertSame(1, Requirement::where('company_id', $this->a->id)->where('status', 'superseded')->count());

        // Another company never sees A's custom field.
        $this->assertNull(collect(\App\Core\Catalogs\CompanyCatalogReadModel::forCompany($this->b)['fields'])->firstWhere('code', 'permis_caces'));
    }

    public function test_catalog_requires_a_configuration_right(): void
    {
        $plain = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'emp', 'name' => 'Salarié', 'is_administrative' => false]);
        Membership::create(['user_id' => $plain->id, 'company_id' => $this->a->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $this->api($plain, 'GET', '/company/catalogs')->assertForbidden();
    }

    public function test_applying_position_requirements_to_people_in_place_is_previewed_then_explicit(): void
    {
        $this->api($this->hr, 'POST', '/workforce/requirements', ['scope' => 'job_role:'.$this->driver->id, 'items' => [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required']]])->assertOk();
        $inPlace = $this->employee('INPLACE');
        $hiring = $this->employee('HIRING', null, ['status' => 'inactive', 'preparatory_at' => now()]);

        // Role authority : the position configuration is prepared only → application refused.
        $view = $this->api($this->hr, 'GET', "/workforce/job-roles/{$this->driver->id}/people-documents")->assertOk();
        $this->assertSame('role', $view->json('authority'));
        $this->assertFalse($view->json('can_apply'));
        $this->api($this->hr, 'POST', "/workforce/job-roles/{$this->driver->id}/apply-requirements", ['employee_ids' => [$inPlace->id]])->assertStatus(422);

        DB::table('company_document_settings')->updateOrInsert(['company_id' => $this->a->id], ['requirements_authority' => 'position']);
        RequirementAuthority::flush();
        $view = $this->api($this->hr, 'GET', "/workforce/job-roles/{$this->driver->id}/people-documents")->assertOk();
        $row = collect($view->json('people'))->firstWhere('id', $inPlace->id);
        $this->assertContains('driving_license', $row['to_request']);
        $this->assertSame(0, DocumentRequest::count(), 'reading creates nothing');

        $res = $this->api($this->hr, 'POST', "/workforce/job-roles/{$this->driver->id}/apply-requirements", ['employee_ids' => [$inPlace->id, $hiring->id]])->assertOk();
        $this->assertContains('driving_license', $res->json('created.'.$inPlace->id));
        $this->assertContains($hiring->id, $res->json('skipped'));
        $count = DocumentRequest::count();
        $this->api($this->hr, 'POST', "/workforce/job-roles/{$this->driver->id}/apply-requirements", ['employee_ids' => [$inPlace->id]])->assertOk();
        $this->assertSame($count, DocumentRequest::count(), 'idempotent : never asked twice');

        // documents.manage is required to apply.
        $viewer = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'v', 'name' => 'V', 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'documents.view'])->pluck('id')->all());
        Membership::create(['user_id' => $viewer->id, 'company_id' => $this->a->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $this->api($viewer, 'POST', "/workforce/job-roles/{$this->driver->id}/apply-requirements", ['employee_ids' => [$inPlace->id]])->assertForbidden();
    }
}
