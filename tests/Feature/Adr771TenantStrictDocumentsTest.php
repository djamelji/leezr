<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Documents\Services\PersonDocumentContinuity;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Tenancy\CompanyContext;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-771 (§4) — the WHOLE document cycle with STRICT tenancy switched on (ADR-733 : a tenant
 * query without a bound company throws instead of reading every tenant).
 *
 * Two companies carry the same shapes of data. Every legitimate path of company A — HTTP, scheduled
 * commands, campaign job, subject transition — must run without ever missing its tenant context,
 * must never read company B, and must leave the rows AND the files of company B byte-identical
 * (fingerprints compared before / after). Process boundaries are simulated : the tenant context is
 * cleared after each HTTP call, exactly as between two requests or two jobs.
 */
class Adr771TenantStrictDocumentsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    /** Tenant tables whose rows of company B must not move. */
    private const TENANT_TABLES = [
        'member_documents', 'document_requests', 'document_request_events', 'document_campaigns',
        'document_campaign_recipients', 'published_payslips', 'memberships', 'workforce_employees',
        'company_document_settings', 'company_audit_logs',
    ];

    private DocumentType $type;

    /** @var array<string, array{company: Company, owner: User, accountless: Employee, employee: Employee, user: User, payslip: PublishedPayslip}> */
    private array $t = [];
    private User $multi; // member of BOTH companies

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $this->type = DocumentType::where('code', 'id_card')->firstOrFail();
        $this->multi = User::factory()->create(['first_name' => 'Multi']);

        foreach (['A', 'B'] as $key) {
            $this->t[$key] = $this->makeTenant($key);
        }

        // From here on : fail-closed tenancy, no tenant bound.
        CompanyContext::clear();
        CompanyContext::$strict = true;
    }

    protected function tearDown(): void
    {
        CompanyContext::$strict = false;
        CompanyContext::clear();
        parent::tearDown();
    }

    private function makeTenant(string $key): array
    {
        $company = Company::create(['name' => "Strict {$key}", 'slug' => 'strict-'.strtolower($key).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => 'workforce'], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        $adminRole = $this->setUpCompanyRbac($company);
        $plain = CompanyRole::create(['company_id' => $company->id, 'key' => 'plain', 'name' => 'Plain', 'is_administrative' => false]);
        DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $this->type->id, 'enabled' => true, 'required_override' => true, 'order' => 10]);

        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        $user = User::factory()->create();
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $plain->id]);
        $company->memberships()->create(['user_id' => $this->multi->id, 'role' => 'user', 'company_role_id' => $plain->id]);

        $mk = fn (array $a) => Employee::withoutGlobalScopes()->create($a + ['company_id' => $company->id, 'first_name' => 'E', 'last_name' => $key, 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid()]);
        $accountless = $mk(['user_id' => null]);
        $employee = $mk(['user_id' => null]); // linked to `$user` during the test

        Storage::disk('local')->put($path = "payslips/{$company->id}/".uniqid().'.pdf', "%PDF payslip {$key}");
        $payslip = PublishedPayslip::withoutGlobalScopes()->create(['company_id' => $company->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->multi->id,
            'period' => '2026-01', 'file_path' => $path, 'file_name' => 'b.pdf', 'file_sha256' => hash('sha256', $path), 'version_no' => 1, 'created_at' => now()]);

        return compact('company', 'owner', 'accountless', 'employee', 'user', 'payslip');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** One HTTP request = one process : the context bound by the middleware does not survive it. */
    private function http(string $tenant, User $actor, string $method, string $uri, array $data = [])
    {
        CompanyContext::clear();
        $response = $this->actingAs($actor)->withHeaders(['X-Company-Id' => $this->t[$tenant]['company']->id])->json($method, '/api'.$uri, $data);
        CompanyContext::clear();
        $this->assertStringNotContainsString('tenant context', strtolower((string) $response->getContent()), "{$method} {$uri} lost its tenant context");

        return $response;
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent("id-{$marker}.pdf", "%PDF-1.4\nSTRICT[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function upload(string $tenant, string $ref, string $marker, ?string $expires = null)
    {
        return $this->http($tenant, $this->t[$tenant]['owner'], 'POST', "/company/people/{$ref}/documents/id_card", [
            'file' => $this->pdf($marker), 'expires_at' => $expires ?? now()->addYear()->toDateString(),
        ]);
    }

    /** Declared cross-tenant read, for the assertions of the test itself. */
    private function g(\Closure $fn)
    {
        return CompanyContext::runGlobal($fn);
    }

    /** Rows of the company in every tenant table + the bytes of every file it references. */
    private function fingerprint(string $tenant): string
    {
        $id = $this->t[$tenant]['company']->id;
        $rows = [];
        foreach (self::TENANT_TABLES as $table) {
            $rows[$table] = DB::table($table)->where('company_id', $id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $rows['document_versions'] = DB::table('document_versions')->whereIn('member_document_id', DB::table('member_documents')->where('company_id', $id)->pluck('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $files = [];
        $paths = collect($rows['member_documents'])->pluck('file_path')->merge(collect($rows['document_versions'])->pluck('file_path'))->merge(collect($rows['published_payslips'])->pluck('file_path'))->filter()->unique()->sort();
        foreach ($paths as $path) {
            $files[$path] = Storage::disk('local')->exists($path) ? hash('sha256', Storage::disk('local')->get($path)) : 'MISSING';
        }
        // Anything stored under the company prefix, referenced or not.
        foreach (Storage::disk('local')->allFiles("documents/{$id}") as $path) {
            $files[$path] ??= hash('sha256', Storage::disk('local')->get($path));
        }
        ksort($files);

        return hash('sha256', json_encode([$rows, $files]));
    }

    private function seedB(): void
    {
        // Company B carries the same shapes as A : a document of an account-less employee (two
        // versions), a linked employee with a submitted document, an expiring document, a request.
        $b = $this->t['B'];
        $this->upload('B', "employee-{$b['accountless']->id}", 'B1')->assertOk();
        $this->upload('B', "employee-{$b['accountless']->id}", 'B2', now()->addDays(10)->toDateString())->assertOk();
        $this->upload('B', "employee-{$b['employee']->id}", 'B3')->assertOk();
        $this->g(fn () => $b['employee']->forceFill(['user_id' => $b['user']->id])->save());
        $this->http('B', $b['user'], 'GET', '/profile/documents')->assertOk(); // adoption happens in B, by B
        $this->http('B', $this->multi, 'POST', '/profile/documents/id_card', ['file' => $this->pdf('B-multi'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();
    }

    // ═══ The cycle ══════════════════════════════════════════════════════════

    public function test_full_document_cycle_of_company_a_never_reads_or_mutates_company_b(): void
    {
        $this->seedB();
        $a = $this->t['A'];
        $b = $this->t['B'];
        $before = $this->fingerprint('B');

        // ── 1. Account-less employee : deposit, replacement, archived version, downloads ──
        $ref = "employee-{$a['accountless']->id}";
        $this->upload('A', $ref, 'A1')->assertOk();
        $this->upload('A', $ref, 'A2', now()->addDays(10)->toDateString())->assertOk();
        $versions = $this->http('A', $a['owner'], 'GET', "/company/people/{$ref}/documents/id_card/versions")->assertOk()->json('versions');
        $this->assertCount(1, $versions);
        $old = $this->http('A', $a['owner'], 'GET', "/company/people/{$ref}/documents/id_card/versions/{$versions[0]['id']}/download")->assertOk();
        $this->assertStringContainsString('STRICT[A1]', $old->streamedContent(), 'the archived version still serves ITS bytes');
        $this->assertStringContainsString('STRICT[A2]', $this->http('A', $a['owner'], 'GET', "/company/people/{$ref}/documents/id_card/download")->assertOk()->streamedContent());

        // ── 2. Ids of company B are unreachable from company A ──
        $bVersion = $this->g(fn () => DocumentVersion::whereIn('member_document_id', MemberDocument::where('company_id', $b['company']->id)->pluck('id'))->value('id'));
        $bMembership = $this->g(fn () => Membership::where('company_id', $b['company']->id)->where('user_id', $b['user']->id)->value('id'));
        foreach ([
            ['GET', "/company/people/employee-{$b['accountless']->id}/documents"],
            ['GET', "/company/people/employee-{$b['accountless']->id}/documents/id_card/download"],
            ['GET', "/company/people/{$ref}/documents/id_card/versions/{$bVersion}/download"],
            ['POST', "/company/people/employee-{$b['accountless']->id}/documents/id_card"],
            ['GET', "/company/members/{$bMembership}/documents"],
            ['PUT', "/company/members/{$bMembership}/documents/id_card/review"],
            ['GET', "/company/people/user-{$b['user']->id}/payslips/{$b['payslip']->id}/download"],
        ] as [$method, $uri]) {
            $status = $this->http('A', $a['owner'], $method, $uri, $method === 'POST' ? ['file' => $this->pdf('X')] : ['status' => 'approved'])->getStatusCode();
            $this->assertContains($status, [403, 404, 422], "{$method} {$uri} must not reach company B (got {$status})");
        }
        // A plain member of A who is NOT a member of B gets nothing from B either.
        $this->assertSame(403, $this->actingAs($a['user'])->withHeaders(['X-Company-Id' => $b['company']->id])->getJson('/api/profile/documents')->getStatusCode());
        CompanyContext::clear();

        // ── 3. Employee linked to an account : adoption, self-service, review, refusal, re-deposit ──
        $eRef = "employee-{$a['employee']->id}";
        $this->upload('A', $eRef, 'A3')->assertOk();
        $this->g(fn () => $a['employee']->forceFill(['user_id' => $a['user']->id])->save());
        $mine = collect($this->http('A', $a['user'], 'GET', '/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertNotNull($mine['upload'] ?? null, 'the document followed the person');
        $mid = $this->g(fn () => Membership::where('company_id', $a['company']->id)->where('user_id', $a['user']->id)->value('id'));
        $this->http('A', $a['owner'], 'PUT', "/company/members/{$mid}/documents/id_card/review", ['status' => 'rejected', 'review_note' => 'Illisible'])->assertOk();
        $this->http('A', $a['user'], 'POST', '/profile/documents/id_card', ['file' => $this->pdf('A4'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();
        $this->http('A', $a['owner'], 'PUT', "/company/members/{$mid}/documents/id_card/review", ['status' => 'approved'])->assertOk();
        $this->assertSame(1, $this->g(fn () => MemberDocument::where('company_id', $a['company']->id)->where('user_id', $a['user']->id)->count()), 'one current document, no duplicate');

        // ── 4. A user who belongs to BOTH companies : each company answers with its own data ──
        $this->http('A', $this->multi, 'POST', '/profile/documents/id_card', ['file' => $this->pdf('A-multi'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();
        $inA = $this->http('A', $this->multi, 'GET', '/profile/documents/id_card/download')->assertOk()->streamedContent();
        $inB = $this->http('B', $this->multi, 'GET', '/profile/documents/id_card/download')->assertOk()->streamedContent();
        $this->assertStringContainsString('STRICT[A-multi]', $inA);
        $this->assertStringContainsString('STRICT[B-multi]', $inB);
        $slipsA = array_column($this->http('A', $this->multi, 'GET', '/profile/payslips')->assertOk()->json('payslips'), 'id');
        $slipsB = array_column($this->http('B', $this->multi, 'GET', '/profile/payslips')->assertOk()->json('payslips'), 'id');
        $this->assertSame([[$a['payslip']->id], [$b['payslip']->id]], [$slipsA, $slipsB]);
        $this->http('A', $this->multi, 'GET', "/profile/payslips/{$b['payslip']->id}/download")->assertStatus(404);

        // ── 5. Compliance & campaign (launch job, sync queue) stay inside company A ──
        $this->http('A', $a['owner'], 'GET', '/company/documents/compliance')->assertOk();
        $campaignId = $this->http('A', $a['owner'], 'POST', '/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'all']])->assertCreated()->json('campaign.id');
        $recipients = $this->g(fn () => DocumentCampaignRecipient::where('document_campaign_id', $campaignId)->get(['company_id', 'document_subject_type', 'document_subject_id']));
        $this->assertNotEmpty($recipients);
        $this->assertSame([$a['company']->id], $recipients->pluck('company_id')->unique()->values()->all());
        $foreignSubjects = [['employee', $b['accountless']->id], ['employee', $b['employee']->id], ['user', $b['user']->id], ['user', $b['owner']->id]];
        foreach ($recipients as $r) {
            $this->assertNotContains([$r->document_subject_type, (int) $r->document_subject_id], $foreignSubjects, 'no person of B in a campaign of A');
        }

        // ── 6. Access removal in A : the documents return to the HR record of A ──
        $this->http('A', $a['owner'], 'DELETE', "/company/members/{$mid}")->assertOk();
        $entry = collect($this->http('A', $a['owner'], 'GET', "/company/people/{$eRef}/documents")->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertTrue((bool) ($entry['has_file'] ?? $entry['upload'] ?? false), 'the HR record keeps the document after the access removal');
        $this->assertSame(0, $this->g(fn () => MemberDocument::where('company_id', $a['company']->id)->where('user_id', $a['user']->id)->count()), 'nothing stays keyed on the ex-account');

        // ── Company B : rows and files byte-identical ──
        $this->assertSame($before, $this->fingerprint('B'), 'company B was read or mutated by the cycle of company A');
        $this->assertFalse(CompanyContext::has());
    }

    public function test_scheduled_lifecycle_runs_per_company_and_touches_only_the_companies_it_is_enabled_for(): void
    {
        $this->seedB();
        $a = $this->t['A'];
        $b = $this->t['B'];
        // A and B : an expiring document, VALIDATED (a renewal only re-opens a settled request — a
        // document still awaiting review is not renewed). Automation ON for A, OFF for B.
        // Known limit, pinned below : an account-less employee has no review workflow, so her deposit
        // stays « submitted » and is never auto-renewed.
        foreach (['A', 'B'] as $key) {
            $t = $this->t[$key];
            $this->upload($key, "user-{$t['user']->id}", "{$key}-expiring", now()->addDays(10)->toDateString())->assertOk();
            $mid = $this->g(fn () => Membership::where('company_id', $t['company']->id)->where('user_id', $t['user']->id)->value('id'));
            $this->http($key, $t['owner'], 'PUT', "/company/members/{$mid}/documents/id_card/review", ['status' => 'approved'])->assertOk();
        }
        $this->upload('A', "employee-{$a['accountless']->id}", 'A1', now()->addDays(10)->toDateString())->assertOk();
        $this->g(function () use ($a, $b) {
            CompanyDocumentSetting::updateOrCreate(['company_id' => $a['company']->id], ['auto_renew_enabled' => true, 'renew_days_before' => 30, 'auto_remind_enabled' => true, 'remind_after_days' => 7]);
            CompanyDocumentSetting::updateOrCreate(['company_id' => $b['company']->id], ['auto_renew_enabled' => false, 'renew_days_before' => 30, 'auto_remind_enabled' => false, 'remind_after_days' => 7]);
        });
        $before = $this->fingerprint('B');
        CompanyContext::clear();

        foreach (['documents:auto-renew', 'documents:auto-remind', 'documents:check-expiration', 'documents:check-expiration'] as $command) {
            $this->artisan($command)->assertSuccessful();
            $this->assertFalse(CompanyContext::has(), "{$command} leaks no tenant context");
        }

        // A : exactly one renewal request, for A's own subject, however many times the commands ran.
        $renewed = $this->g(fn () => DocumentRequestEvent::where('event', DocumentRequestEvent::E_RENEWED)->get(['company_id', 'document_request_id']));
        $this->assertCount(1, $renewed, 'idempotent : one renewal');
        $this->assertSame($a['company']->id, (int) $renewed[0]->company_id);
        $request = $this->g(fn () => DocumentRequest::find($renewed[0]->document_request_id));
        $this->assertSame([$a['company']->id, 'user', $a['user']->id], [(int) $request->company_id, $request->document_subject_type, (int) $request->document_subject_id]);
        // Pinned limit : the account-less deposit of A is still « submitted », not renewed.
        $this->assertSame('submitted', $this->g(fn () => DocumentRequest::where('company_id', $a['company']->id)->where('document_subject_type', 'employee')->where('document_subject_id', $a['accountless']->id)->value('status')));

        // B : automation off → strictly unchanged (its expiring document is NOT renewed by A's settings).
        $this->assertSame($before, $this->fingerprint('B'), 'the scheduled lifecycle of A touched company B');

        // B switched on : now B gets ITS renewal, and A is not processed twice.
        $beforeA = $this->fingerprint('A');
        $this->g(fn () => CompanyDocumentSetting::where('company_id', $b['company']->id)->update(['auto_renew_enabled' => true]));
        $this->artisan('documents:auto-renew')->assertSuccessful();
        $ofB = $this->g(fn () => DocumentRequestEvent::where('event', DocumentRequestEvent::E_RENEWED)->where('company_id', $b['company']->id)->count());
        $this->assertSame(1, $ofB);
        $this->assertSame($beforeA, $this->fingerprint('A'), 'company A is untouched by the renewal of company B');
    }

    public function test_subject_transitions_bind_their_own_tenant_and_stay_inside_it(): void
    {
        $this->seedB();
        $a = $this->t['A'];
        $b = $this->t['B'];
        $this->upload('A', "employee-{$a['employee']->id}", 'A3')->assertOk();
        $this->g(fn () => $a['employee']->forceFill(['user_id' => $a['user']->id])->save());
        $before = $this->fingerprint('B');
        CompanyContext::clear();

        // Called with NO tenant bound (job, command) : the authority binds the company itself.
        $this->assertGreaterThan(0, PersonDocumentContinuity::reconcile($a['company']));
        $this->assertSame(0, PersonDocumentContinuity::reconcile($a['company']), 'idempotent');
        $this->assertFalse(CompanyContext::has());

        // Called from INSIDE another company (a request of B) : it still works on A only, then
        // gives the context back to B.
        CompanyContext::runFor($b['company'], function () use ($a, $b) {
            PersonDocumentContinuity::adopt($a['company'], $a['user']);
            $this->assertSame($b['company']->id, CompanyContext::id(), 'the caller keeps its own tenant');
        });

        $doc = $this->g(fn () => MemberDocument::where('company_id', $a['company']->id)->where('user_id', $a['user']->id)->first());
        $this->assertNotNull($doc);
        $this->assertSame($a['employee']->id, (int) $doc->origin_employee_id);
        $this->assertSame($before, $this->fingerprint('B'));
    }

    public function test_a_tenant_query_without_context_still_fails_closed(): void
    {
        // The guarantee this file relies on : strict mode is really ON, nothing here ran by accident.
        $this->expectException(\App\Core\Tenancy\MissingTenantContextException::class);
        MemberDocument::query()->count();
    }
}
