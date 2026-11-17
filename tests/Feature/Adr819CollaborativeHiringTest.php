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
 * ADR-819 (Company 2.0) — collaborative hiring on ONE file : started on site, continued remotely, taken over by
 * another authorised manager ; per-item progress ; history and participants ; a stale write is refused (409)
 * instead of silently overwriting what the candidate or another manager changed meanwhile.
 */
class Adr819CollaborativeHiringTest extends TestCase
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

    public function test_one_file_on_site_then_remote_then_taken_over_with_history_and_stale_writes_refused(): void
    {
        $id = $this->file();
        $second = $this->member($this->a, ['workforce.view', 'workforce.manage']); // another authorised manager

        $file = $this->api($this->hr, 'GET', "/workforce/onboarding-files/{$id}/file")->assertOk();
        $this->assertSame('waiting_company', $file->json('bucket'));
        $this->assertSame('to_fill', collect($file->json('fields'))->firstWhere('code', 'birth_date')['state']);
        $this->assertSame('to_fill', collect($file->json('documents'))->firstWhere('code', 'id_card')['state']);
        $rev = $file->json('revision');

        // Started on site by HR …
        $cookie = $this->cookieOf($this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/on-site"));
        $this->portal('POST', '/documents/id_card', ['files' => [UploadedFile::fake()->image('id.jpg')]], $cookie)->assertOk();

        // … the file changed : a manager holding the old revision cannot overwrite it.
        $this->api($second, 'POST', "/workforce/onboarding-files/{$id}/request-changes", ['message' => 'Merci de compléter.', 'revision' => $rev])
            ->assertStatus(409)->assertJsonPath('code', 'stale_revision');

        // … continued remotely by the candidate (same file, answers kept) …
        $this->api($this->hr, 'PUT', "/workforce/onboarding-files/{$id}/contact", ['candidate_email' => 'nadia@example.test'])->assertOk();
        $url = $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/link", ['days' => 7])->assertOk()->json('url');
        $remote = $this->cookieOf($this->portal('POST', '/session', ['token' => substr($url, strpos($url, '#t=') + 3)])->assertOk());
        OnboardingInvitation::whereKey($id)->update(['code_hash' => hash('sha256', $id.'|123456'), 'code_expires_at' => now()->addMinutes(5), 'code_attempts' => 0]);
        $this->portal('POST', '/verify', ['code' => '123456'], $remote)->assertOk(); // code reçu par e-mail (fixé pour le test)
        $this->portal('PUT', '/values', ['values' => ['birth_date' => '1994-02-01']], $remote)->assertOk();
        $this->portal('POST', '/submit', [], $remote)->assertOk();

        // … taken over by ANOTHER manager with the fresh revision.
        $file = $this->api($second, 'GET', "/workforce/onboarding-files/{$id}/file")->assertOk();
        $this->assertSame('to_verify', $file->json('bucket'));
        $this->assertSame('to_verify', collect($file->json('fields'))->firstWhere('code', 'birth_date')['state']);
        $this->api($second, 'POST', "/workforce/onboarding-files/{$id}/validate", ['decisions' => ['birth_date' => 'accept'], 'revision' => $file->json('revision')])->assertOk();

        $file = $this->api($this->hr, 'GET', "/workforce/onboarding-files/{$id}/file")->assertOk();
        $this->assertSame('to_finalize', $file->json('bucket'));
        $this->assertSame('validated', collect($file->json('fields'))->firstWhere('code', 'birth_date')['state']);
        $actions = collect($file->json('history'))->pluck('action');
        foreach (['onboarding.created', 'onboarding.on_site_opened', 'onboarding.link_issued', 'onboarding.submitted', 'onboarding.validated'] as $a) {
            $this->assertContains($a, $actions, $a);
        }
        $kinds = collect($file->json('participants'))->pluck('kind');
        $this->assertSame(2, $kinds->filter(fn ($k) => $k === 'user')->count(), 'both managers are participants');
        $this->assertContains('candidate', $kinds);
        $this->assertGreaterThanOrEqual(1, $file->json('reminders'));

        // Still ONE file, ONE preparatory record for this person.
        $this->assertSame(1, OnboardingInvitation::where('employee_id', OnboardingInvitation::find($id)->employee_id)->count());

        // Finalised with the fresh revision → done.
        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/finalize", ['hire_date' => '2026-12-16', 'revision' => $file->json('revision')])->assertOk();
        $row = collect($this->api($this->hr, 'GET', '/workforce/onboarding-files')->json('invitations'))->firstWhere('id', $id);
        $this->assertSame('done', $row['bucket']);

        // Another company never reads the file.
        $other = User::factory()->create();
        Membership::create(['user_id' => $other->id, 'company_id' => $this->b->id, 'role' => 'owner']);
        $this->api($other, 'GET', "/workforce/onboarding-files/{$id}/file", [], $this->b)->assertNotFound();
    }

    public function test_abandoned_file_is_classified_and_requires_hiring_rights(): void
    {
        $id = $this->file();
        $this->api($this->member($this->a, ['workforce.view']), 'GET', "/workforce/onboarding-files/{$id}/file")->assertForbidden();
        $this->api($this->hr, 'POST', "/workforce/onboarding-files/{$id}/revoke")->assertOk();
        $this->assertSame('abandoned', $this->api($this->hr, 'GET', "/workforce/onboarding-files/{$id}/file")->json('bucket'));
    }
}
