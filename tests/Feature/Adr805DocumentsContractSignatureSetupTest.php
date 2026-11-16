<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Events\ModuleEnabled;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Contract\Services\ContractTemplateService;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSetting;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-805 — the common assistant on Documents RH, Contrats, Signatures : real prerequisites, related (other
 * module) capabilities shown read-only, server-enforced personal / collective / sensitive access, deactivation
 * impact (nothing interrupted silently), reactivation. Positive checks assert 200/201 AND the state change.
 */
class Adr805DocumentsContractSignatureSetupTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    private Company $company;

    private User $owner;

    private CompanyRole $chef;

    private CompanyRole $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);
        [$this->company, $this->owner] = $this->makeCompany('A');
        $this->chef = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $this->agent = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'agent', 'name' => 'Agent', 'is_administrative' => false]);
    }

    private function makeCompany(string $tag): array
    {
        $c = Company::create(['name' => "D805 $tag", 'slug' => 'd805-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($c);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($c);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$c, $owner];
    }

    private function as(User $u, ?Company $c = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->company)->id]);
    }

    private function perms(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function start(string $module): array
    {
        ModuleEnabled::dispatch($this->company, $module);

        return $this->as($this->owner)->getJson("/api/modules/$module/setup")->assertOk()->json();
    }

    private function apply(string $module, array $changes)
    {
        $fp = $this->as($this->owner)->postJson("/api/modules/$module/setup/access/preview", ['changes' => $changes])->json('fingerprint');

        return $this->as($this->owner)->putJson("/api/modules/$module/setup/access", ['changes' => $changes, 'fingerprint' => $fp]);
    }

    private function confirm(string $module, ?string $settingsStep = null)
    {
        $rev = $settingsStep
            ? $this->as($this->owner)->postJson("/api/modules/$module/setup/steps", ['step' => $settingsStep])->json('revision')
            : $this->as($this->owner)->getJson("/api/modules/$module/setup")->json('revision');

        return $this->as($this->owner)->postJson("/api/modules/$module/setup/confirm", ['revision' => $rev]);
    }

    private function setupState(string $module): array
    {
        return $this->as($this->owner)->getJson("/api/modules/$module/setup")->json();
    }

    private function member(CompanyRole $role, ?Employee &$employee = null, ?int $managerId = null): User
    {
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $employee = $this->employee($u->id, $managerId);

        return $u;
    }

    private function employee(?int $userId = null, ?int $managerId = null, ?Company $c = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => ($c ?? $this->company)->id, 'user_id' => $userId, 'first_name' => 'E', 'last_name' => uniqid(),
            'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'manager_id' => $managerId, 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function doc(int $employeeId, string $subjectType = 'employee', ?Company $c = null): GeneratedDocument
    {
        $c ??= $this->company;
        $tpl = DocumentTemplate::create(['company_id' => $c->id, 'code' => 'tpl-'.uniqid(), 'name' => 'Attestation', 'subject_type' => 'employee',
            'body_html' => '<p>x</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true]);
        $path = 'documents/generated/'.$c->id.'/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Doc</h1>')->output());

        return GeneratedDocument::create(['company_id' => $c->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => $subjectType, 'subject_id' => $employeeId, 'generated_by' => $this->owner->id, 'generated_at' => now(),
            'file_path' => $path, 'file_size_bytes' => 10, 'generation_snapshot' => ['x' => 1], 'signature_status' => GeneratedDocument::SIGNATURE_NONE]);
    }

    private function contractTemplate(bool $published = true): DocumentTemplate
    {
        return DocumentTemplate::create(['company_id' => $this->company->id, 'code' => 'ct-'.uniqid(), 'name' => 'CDI', 'subject_type' => ContractTemplateService::SUBJECT,
            'body_html' => '<p>{{ company.name }}</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true, 'is_system' => false,
            'metadata' => ['status' => $published ? ContractTemplateService::STATUS_PUBLISHED : ContractTemplateService::STATUS_DRAFT, 'is_default' => false, 'contract_type' => 'cdi']]);
    }

    // ── contract & adaptive steps ───────────────────────────────────────

    public function test_steps_follow_the_real_prerequisites_and_activation_grants_nothing(): void
    {
        $this->assertSame(['access'], $this->start('workforce_documents')['steps'], 'no mandatory setting → no settings step');
        $contract = $this->start('workforce_contract');
        $signature = $this->start('workforce_signature');
        $this->assertSame(['access', 'contract_templates'], $contract['steps']);
        $this->assertSame(['access', 'signature_settings'], $signature['steps']);
        $this->assertSame(['workforce', 'workforce_documents'], array_column($contract['module']['requires'], 'key'));
        $this->assertSame([], $this->perms($this->chef));
        $this->assertSame([], $this->perms($this->agent));
    }

    public function test_capabilities_scopes_and_related_access_from_other_modules(): void
    {
        $d = $this->start('workforce_documents');
        $caps = collect($d['capabilities'])->keyBy('key');
        $this->assertSame('team', $caps['workforce_documents.employee']['scope']);
        $this->assertTrue($caps['workforce_documents.management']['administrative_only']);
        $this->assertSame('team', collect($this->start('workforce_signature')['capabilities'])->firstWhere('key', 'workforce_signature.readonly')['scope']);

        // Related : shown with their real holders, never duplicated as this module's capabilities.
        $this->chef->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage'])->pluck('id')->all());
        $related = collect($this->setupState('workforce_documents')['related'])->keyBy('key');
        $this->assertSame(['workforce.team', 'workforce.hr_management', 'documents.management'], $related->keys()->all());
        $this->assertSame('workforce', $related['workforce.team']['module']);
        $this->assertContains(['role_id' => $this->chef->id, 'role' => 'Chef', 'holds' => 'partial'], $related['workforce.team']['holders']);
        $this->assertNotContains($this->agent->id, array_column($related['workforce.team']['holders'], 'role_id'));
        $this->assertTrue($related['workforce.hr_management']['sensitive']);
        $this->assertNotContains('workforce.team', $caps->keys()->all());
    }

    public function test_operational_role_refused_and_delegation_policy_reused(): void
    {
        foreach (['workforce_documents' => 'workforce_documents.management', 'workforce_contract' => 'workforce_contract.management', 'workforce_signature' => 'workforce_signature.management'] as $m => $cap) {
            $this->start($m);
            $p = $this->as($this->owner)->postJson("/api/modules/$m/setup/access/preview", ['changes' => [['role_id' => $this->agent->id, 'capability' => $cap, 'grant' => true]]])->json('plan.0');
            $this->assertSame('operational_role', $p['blocked'], $cap);
        }
        $gest = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'gest', 'name' => 'Gest', 'is_administrative' => true]);
        $gest->syncPermissionsSafe(CompanyPermission::whereIn('key', ['modules.manage', 'roles.manage', 'workforce_documents.view'])->pluck('id')->all());
        $actor = $this->member($gest);
        $p = $this->as($actor)->postJson('/api/modules/workforce_signature/setup/access/preview', ['changes' => [['role_id' => $this->chef->id, 'capability' => 'workforce_signature.management', 'grant' => true]]])->json('plan.0');
        $this->assertSame('beyond_your_rights', $p['blocked']);
        $this->as($this->member($this->agent))->getJson('/api/modules/workforce_signature/deactivation-impact')->assertForbidden();
    }

    // ── server-enforced access : personal / collective / sensitive (positive AND negative) ─

    public function test_personal_scope_documents_and_signatures(): void
    {
        $this->start('workforce_documents');
        $this->start('workforce_signature');
        $this->apply('workforce_documents', [['role_id' => $this->agent->id, 'capability' => 'workforce_documents.employee', 'grant' => true]])->assertOk();
        $this->apply('workforce_signature', [['role_id' => $this->agent->id, 'capability' => 'workforce_signature.readonly', 'grant' => true]])->assertOk();
        $agent = $this->member($this->agent, $me);
        $mine = $this->doc($me->id);
        $other = $this->doc($this->employee()->id);

        $ids = collect($this->as($agent)->getJson('/api/workforce/documents')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids, 'workforce_documents.view = self + direct reports only');
        $this->as($agent)->get("/api/workforce/documents/{$mine->id}/download")->assertOk();
        $this->as($agent)->get("/api/workforce/documents/{$other->id}/download")->assertForbidden();
        $this->as($agent)->getJson("/api/workforce/documents/{$mine->id}")->assertOk();
        $this->as($agent)->getJson("/api/workforce/documents/{$other->id}")->assertForbidden(); // ADR-805 : la fiche suit le périmètre
        $this->as($agent)->getJson('/api/workforce/signature')->assertOk();
        $this->as($agent)->getJson('/api/workforce/signature/settings')->assertForbidden();
        $this->as($agent)->getJson('/api/workforce/contract/templates')->assertForbidden();
        $this->as($agent)->postJson('/api/workforce/signature', ['generated_document_id' => $mine->id, 'signers' => [['role' => 'employee', 'name' => 'A', 'email' => 'a@x.test']]])->assertForbidden();
        $this->assertSame(0, SignatureRequest::withoutGlobalScopes()->count());
    }

    public function test_generation_is_limited_to_subjects_the_actor_could_open(): void
    {
        $this->start('workforce_documents');
        $this->apply('workforce_documents', [['role_id' => $this->chef->id, 'capability' => 'workforce_documents.management', 'grant' => true]])->assertOk();
        $chef = $this->member($this->chef, $boss);
        $report = $this->employee(null, $boss->id);
        $stranger = $this->employee();
        DocumentTemplate::create(['company_id' => $this->company->id, 'code' => 'att', 'name' => 'Attestation', 'subject_type' => 'employee',
            'body_html' => '<p>{{ company.name }}</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true]);
        $this->as($chef)->postJson('/api/workforce/documents/generate', ['template_code' => 'att', 'subject_type' => 'employee', 'subject_id' => $report->id])->assertCreated();
        $this->as($chef)->postJson('/api/workforce/documents/generate', ['template_code' => 'att', 'subject_type' => 'employee', 'subject_id' => $stranger->id])->assertForbidden();
        $this->assertSame([$report->id], GeneratedDocument::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('subject_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_management_creates_a_signature_request_and_reads_settings(): void
    {
        $this->start('workforce_signature');
        $this->start('workforce_contract');
        $this->apply('workforce_signature', [['role_id' => $this->chef->id, 'capability' => 'workforce_signature.management', 'grant' => true]])->assertOk();
        $this->apply('workforce_contract', [['role_id' => $this->chef->id, 'capability' => 'workforce_contract.management', 'grant' => true]])->assertOk();
        $this->chef->syncPermissionsSafe(CompanyPermission::whereIn('key', array_merge($this->perms($this->chef), ['workforce.view', 'workforce.manage']))->pluck('id')->all()); // collective : Workforce HR view
        $chef = $this->member($this->chef);
        $doc = $this->doc($this->employee()->id);

        $this->as($chef)->getJson('/api/workforce/signature/settings')->assertOk();
        $this->contractTemplate();
        $this->assertNotEmpty($this->as($chef)->getJson('/api/workforce/contract/templates')->assertOk()->json('data'));
        $this->as($chef)->postJson('/api/workforce/signature', ['generated_document_id' => $doc->id, 'signers' => [['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]])
            ->assertStatus(201);
        $req = SignatureRequest::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();
        $this->assertSame($doc->id, (int) $req->generated_document_id);
        $this->assertSame('internal', $req->provider);
    }

    public function test_sensitive_content_needs_compensation_read_and_other_tenant_is_invisible(): void
    {
        $this->start('workforce_documents');
        $this->apply('workforce_documents', [['role_id' => $this->chef->id, 'capability' => 'workforce_documents.management', 'grant' => true]])->assertOk();
        $chef = $this->member($this->chef);
        $payroll = $this->doc(999999, 'payroll_run'); // company-level, embeds pay
        $this->as($chef)->get("/api/workforce/documents/{$payroll->id}/download")->assertForbidden();
        $this->chef->syncPermissionsSafe(CompanyPermission::whereIn('key', array_merge($this->perms($this->chef), ['workforce.compensation_read']))->pluck('id')->all());
        $this->as($chef)->get("/api/workforce/documents/{$payroll->id}/download")->assertOk();

        [$b] = $this->makeCompany('B');
        $foreign = $this->doc($this->employee(null, null, $b)->id, 'employee', $b);
        $this->as($chef)->getJson("/api/workforce/documents/{$foreign->id}")->assertNotFound();
        $this->as($chef)->get("/api/workforce/documents/{$foreign->id}/download")->assertNotFound();
    }

    // ── readiness : real prerequisites, recommendations, effective settings ─

    public function test_contract_needs_a_published_template_then_detects_effective_changes_only(): void
    {
        $this->start('workforce_contract');
        $this->apply('workforce_contract', [])->assertOk();
        $this->contractTemplate(false); // draft only : not usable by the generator
        $this->confirm('workforce_contract', 'contract_templates')->assertStatus(422)->assertJsonPath('missing', ['contract_template_published']);

        $tpl = $this->contractTemplate();
        $this->confirm('workforce_contract')->assertOk()->assertJsonPath('status', 'ready'); // no default : recommendation only
        $this->assertFalse(collect($this->setupState('workforce_contract')['readiness'])->firstWhere('key', 'contract_template_default')['ok']);

        $tpl->forceFill(['metadata' => $tpl->metadata])->save(); // same values rewritten
        $this->assertSame('ready', $this->setupState('workforce_contract')['status']);
        $tpl->forceFill(['metadata' => array_merge($tpl->metadata, ['is_default' => true])])->save();
        $this->assertContains('settings_changed', $this->setupState('workforce_contract')['stale_reasons']);
    }

    public function test_signature_needs_a_resolvable_provider_and_reports_test_provider(): void
    {
        $this->start('workforce_signature');
        $this->apply('workforce_signature', [])->assertOk();
        $this->confirm('workforce_signature', 'signature_settings')->assertOk()->assertJsonPath('status', 'ready');
        $rec = collect($this->setupState('workforce_signature')['readiness'])->keyBy('key');
        $this->assertTrue($rec['signature_provider_available']['ok']);
        $this->assertFalse($rec['signature_provider_production']['ok'], 'internal provider = test tool, said so');
        $this->assertFalse($rec['signature_provider_production']['required']);

        $svc = app(SignatureSettingsService::class);
        $svc->update($this->company->id, ['validity_days' => $svc->resolve($this->company->id)['validity_days']]); // same value
        $this->assertSame('ready', $this->setupState('workforce_signature')['status']);
        $svc->update($this->company->id, ['validity_days' => 5]);
        $this->assertContains('settings_changed', $this->setupState('workforce_signature')['stale_reasons']);

        SignatureSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['provider' => 'nope']);
        $this->assertContains('missing:signature_provider_available', $this->setupState('workforce_signature')['stale_reasons']);
    }

    // ── deactivation : explicit, nothing interrupted silently, nothing lost ─

    public function test_deactivation_impact_and_in_flight_signature_keeps_running(): void
    {
        $this->start('workforce_signature');
        $this->apply('workforce_signature', [['role_id' => $this->chef->id, 'capability' => 'workforce_signature.management', 'grant' => true]])->assertOk();
        $this->chef->syncPermissionsSafe(CompanyPermission::whereIn('key', array_merge($this->perms($this->chef), ['workforce.view', 'workforce.manage']))->pluck('id')->all());
        $chef = $this->member($this->chef);
        $doc = $this->doc($this->employee()->id);
        $this->as($chef)->postJson('/api/workforce/signature', ['generated_document_id' => $doc->id, 'signers' => [['role' => 'employee', 'name' => 'Emp', 'email' => 'emp@x.test', 'order' => 1]]])->assertStatus(201);

        $impact = $this->as($this->owner)->getJson('/api/modules/workforce_documents/deactivation-impact')->assertOk()->json();
        $this->assertSame(['workforce_documents', 'workforce_contract', 'workforce_signature'], $impact['modules'], 'dependents go with it');
        $items = collect($impact['items'])->keyBy('key');
        $this->assertSame(1, $items['issued_documents']['count']);
        $this->assertSame(1, $items['signatures_in_progress']['count']);
        $this->assertTrue($items['signatures_in_progress']['continues']);

        // Deactivate : nothing deleted, the request is not cancelled, the signer's public path is not module-gated.
        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'workforce_signature')->delete();
        $this->as($chef)->getJson('/api/workforce/signature')->assertForbidden();
        $req = SignatureRequest::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();
        $this->assertFalse($req->isTerminal());
        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/public/signature') || str_starts_with($r->uri(), 'api/webhooks/signatures')) {
                $this->assertEmpty(array_filter($r->gatherMiddleware(), fn ($m) => str_contains((string) $m, 'use-module')), $r->uri());
            }
        }

        // Reactivate : same request, same settings, same rights ; « ready » confirmed again.
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce_signature', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        ModuleEnabled::dispatch($this->company, 'workforce_signature');
        $this->as($chef)->getJson('/api/workforce/signature')->assertOk()->assertJsonPath('data.0.id', $req->id);
        $this->assertContains('workforce_signature.manage', $this->perms($this->chef));
        $this->assertSame(1, GeneratedDocument::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }
}
