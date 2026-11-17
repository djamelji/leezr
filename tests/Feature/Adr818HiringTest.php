<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Requirements\RequirementAuthority;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Onboarding\Http\OnboardingPortalController;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-818 (chantier A) — « Embaucher » on the existing onboarding engine :
 *  - on-site mode = the SAME portal opened by HR (verified in person), remote link keeps working ;
 *  - finalisation activates the preparatory record with its employment data, once, after validation ;
 *  - per-company requirement authority (role → position) : explicit, previewed, reversible, isolated ;
 *    no request regenerated, nothing changes for companies that did not switch.
 */
class Adr818HiringTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $a;
    private Company $b;
    private User $hr;
    private User $owner;
    private JobRole $driver;
    private CompanyRole $driverRole;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('quarantine');
        Mail::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        config(['documents.scanner.driver' => 'simulated']);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $this->a = $this->company('A');
        $this->b = $this->company('B');
        $admin = $this->setUpCompanyRbac($this->a);
        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        $this->hr = $this->member($this->a, ['workforce.view', 'workforce.manage', 'workforce.sensitive_read', 'documents.view', 'documents.manage']);
        $this->driver = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Livreur', 'level' => 'agent', 'source' => 'company']);
        // Historical rule : the driver ACCESS ROLE requires the driving licence.
        $this->driverRole = CompanyRole::create(['company_id' => $this->a->id, 'key' => 'drv', 'name' => 'Chauffeur', 'is_administrative' => false,
            'doc_config' => [['code' => 'driving_license', 'visible' => true, 'required' => true, 'order' => 1]]]);
    }

    private function company(string $n): Company
    {
        $c = Company::create(['name' => "Société {$n}", 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => 'workforce'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        foreach (FieldDefinition::whereNull('company_id')->whereIn('code', ['birth_date', 'phone'])->get() as $i => $d) {
            FieldActivation::create(['company_id' => $c->id, 'field_definition_id' => $d->id, 'enabled' => true, 'required_override' => false, 'order' => $i]);
        }
        foreach (DocumentType::where('is_system', true)->get() as $i => $t) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => $t->id, 'enabled' => true, 'order' => $i, 'required_override' => $t->code === 'id_card']);
        }

        return $c;
    }

    private function member(Company $c, array $perms, ?CompanyRole $role = null): User
    {
        if (! $role) {
            $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => true]);
            $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        }
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function api(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api'.$uri, $body);
    }

    private function portal(string $method, string $uri, array $body = [], ?string $cookie = null)
    {
        $this->app['auth']->forgetGuards();
        $req = $this->withHeaders(['Accept' => 'application/json'])->withCredentials();
        $req->defaultCookies = [];
        $req->unencryptedCookies = [];
        if ($cookie) {
            $req = $req->withUnencryptedCookie(OnboardingPortalController::COOKIE, $cookie);
        }

        return $req->json($method, '/api/public/onboarding'.$uri, $body);
    }

    private function cookieOf($res): string
    {
        return collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === OnboardingPortalController::COOKIE)->getValue();
    }

    private function file(array $extra = []): int
    {
        return $this->api($this->hr, 'POST', '/workforce/onboarding-files', $extra + ['first_name' => 'Nadia', 'last_name' => 'Recrue', 'hire_date' => '2026-12-15',
            'job_role_id' => $this->driver->id, 'items' => [['kind' => 'field', 'code' => 'birth_date', 'required' => false], ['kind' => 'document', 'code' => 'id_card', 'required' => true]],
            'purpose' => 'hiring_onboarding', 'legal_basis' => 'contract_preparation', 'notice_text' => 'Vos informations servent à préparer votre embauche.'])->assertCreated()->json('id');
    }

    public function test_on_site_mode_opens_the_same_portal_verified_in_person_and_remote_link_keeps_working(): void
    {
        $id = $this->file(); // no e-mail at all : on site does not need a second channel
        $res = $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/on-site")->assertOk()->assertJsonPath('url', '/onboarding');
        $cookie = $this->cookieOf($res);
        $this->assertNotEmpty($cookie);
        $this->assertNotNull(\App\Modules\Workforce\Onboarding\Models\OnboardingSession::where('session_hash', hash('sha256', $cookie))->value('verified_at'));

        $this->portal('GET', '', [], $cookie)->assertOk();
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1994-02-01']], $cookie)->assertOk();
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie)->assertOk();
        $this->assertSame(OnboardingInvitation::IN_PROGRESS, OnboardingInvitation::find($id)->status);

        // Continue remotely afterwards : a link can still be issued, the on-site answers are kept.
        $this->api($this->hr, 'PUT', "/workforce/onboarding-files/{$id}/contact", ['candidate_email' => 'nadia@example.test'])->assertOk();
        $url = $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/link", ['days' => 7])->assertOk()->json('url');
        $remote = $this->cookieOf($this->portal('POST', '/session', ['token' => substr($url, strpos($url, '#t=') + 3)])->assertOk());
        $view = $this->portal('GET', '', [], $remote)->assertOk()->json();
        $this->assertSame('1994-02-01', collect($view['fields'])->firstWhere('code', 'birth_date')['value']);

        // Rights : a member without workforce.manage cannot open a file on site ; another company cannot see it.
        $this->api($this->member($this->a, ['workforce.view']), 'POST', "/workforce/onboarding-files/{$id}/on-site")->assertForbidden();
        $other = User::factory()->create();
        Membership::create(['user_id' => $other->id, 'company_id' => $this->b->id, 'role' => 'owner']);
        $this->api($other, 'POST', "/workforce/onboarding-files/{$id}/on-site", [], $this->b)->assertNotFound();
    }

    public function test_finalisation_activates_the_hire_once_after_validation(): void
    {
        $id = $this->file();
        $emp = Employee::withoutGlobalScopes()->find(OnboardingInvitation::find($id)->employee_id);
        $this->assertTrue($emp->isPreparatory());
        $manager = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'M', 'last_name' => 'Gr', 'status' => 'active', 'hire_date' => '2020-01-01']);
        $left = Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'first_name' => 'L', 'last_name' => 'Ft', 'status' => 'terminated', 'hire_date' => '2020-01-01']);

        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/finalize", ['hire_date' => '2026-12-15'])->assertStatus(422); // not validated

        $cookie = $this->cookieOf($this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/on-site"));
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1994-02-01']], $cookie)->assertOk();
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie)->assertOk();
        $this->portal('POST', '/submit', [], $cookie)->assertOk();
        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/validate", ['decisions' => ['birth_date' => 'accept']])->assertOk();

        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/finalize", ['hire_date' => '2026-12-15', 'manager_id' => $left->id])->assertStatus(422);
        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/finalize", ['hire_date' => '2026-12-16', 'manager_id' => $manager->id])
            ->assertOk()->assertJsonPath('person_ref', 'employee-'.$emp->id);
        $emp->refresh();
        $this->assertSame('active', $emp->status);
        $this->assertFalse($emp->isPreparatory());
        $this->assertSame($this->driver->id, $emp->job_role_id);
        $this->assertSame($manager->id, $emp->manager_id);
        $this->assertSame('2026-12-16', $emp->hire_date->toDateString());
        $this->assertNull($emp->user_id, 'no account created by the finalisation');
        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/finalize", ['hire_date' => '2026-12-16'])->assertStatus(422); // once

        $row = collect($this->api($this->hr, 'GET', '/workforce/onboarding-files')->assertOk()->json('invitations'))->firstWhere('id', $id);
        $this->assertTrue($row['finalized']);
        $this->assertSame('Livreur', $row['job_role']);
    }

    public function test_requirement_authority_switch_is_explicit_previewed_reversible_and_isolated(): void
    {
        $driverUser = $this->member($this->a, [], $this->driverRole);
        Employee::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'user_id' => $driverUser->id, 'first_name' => 'D', 'last_name' => 'R', 'status' => 'active', 'hire_date' => '2024-01-01', 'job_role_id' => $this->driver->id]);
        $licence = DocumentType::where('code', 'driving_license')->value('id');
        $required = fn () => (bool) collect(DocumentResolverService::resolve($driverUser, $this->a->id, 'drv', 'FR', DocumentType::SCOPE_COMPANY_USER))->firstWhere('code', 'driving_license')['required'];
        $requests = DocumentRequest::withoutGlobalScopes()->count();

        $this->assertSame(RequirementAuthority::ROLE, $this->api($this->owner, 'GET', '/workforce/requirements/authority')->assertOk()->json('authority'));
        $this->assertTrue($required(), 'historical : the role requires the licence');

        // Preview : the licence would no longer be required (not adopted on the position) → switch refused.
        $preview = $this->api($this->owner, 'GET', '/workforce/requirements/authority')->json();
        $this->assertFalse($preview['ready']);
        $this->assertSame('driving_license', $preview['lost'][0]['code']);
        $this->api($this->owner, 'PUT', '/workforce/requirements/authority', ['authority' => 'position'])->assertStatus(422)->assertJsonValidationErrors('accept_losses');
        // documents.configure alone cannot switch the company's authority.
        $this->api($this->member($this->a, ['documents.configure']), 'PUT', '/workforce/requirements/authority', ['authority' => 'position'])->assertForbidden();

        // Take over the licence on the position (ADR-817), preview becomes ready, then switch.
        $this->api($this->owner, 'POST', '/workforce/requirements', ['scope' => 'job_role:'.$this->driver->id, 'items' => [['kind' => 'document', 'code' => 'driving_license', 'state' => 'required']]])->assertOk();
        $ready = $this->api($this->owner, 'GET', '/workforce/requirements/authority')->json();
        $this->assertTrue($ready['ready'], json_encode($ready['lost']));
        $this->api($this->owner, 'PUT', '/workforce/requirements/authority', ['authority' => 'position'])->assertOk()->assertJsonPath('authority', 'position');
        RequirementAuthority::flush();
        $this->assertTrue($required(), 'position : the licence comes from the position now');

        // A person with the driver ROLE but no driver POSITION is no longer asked for the licence.
        $office = JobRole::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'title' => 'Bureau', 'level' => 'agent', 'source' => 'company']);
        Employee::withoutGlobalScopes()->where('user_id', $driverUser->id)->update(['job_role_id' => $office->id]);
        $this->assertFalse(DocumentResolverService::subjectMap($this->a->id, 'drv', $office->id, 'FR', DocumentType::SCOPE_COMPANY_USER)[$licence]['required']);

        $this->assertSame($requests, DocumentRequest::withoutGlobalScopes()->count(), 'no request created or regenerated');
        $this->assertSame(RequirementAuthority::ROLE, RequirementAuthority::of($this->b->id), 'other company untouched');

        // Back to role : historical computation restored at once.
        $this->api($this->owner, 'PUT', '/workforce/requirements/authority', ['authority' => 'role'])->assertOk();
        RequirementAuthority::flush();
        $this->assertTrue(DocumentResolverService::subjectMap($this->a->id, 'drv', $office->id, 'FR', DocumentType::SCOPE_COMPANY_USER)[$licence]['required']);
    }
}
