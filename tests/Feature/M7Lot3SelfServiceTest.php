<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Navigation\NavBuilder;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-769 (M7 LOT 3) — unified self-service. Self-service is OWNERSHIP, never a permission : every
 * case goes through the real HTTP path and checks what the connected person gets for herself —
 * with or without Workforce, administrator or not, with or without an HR record, in one company
 * or several.
 */
class M7Lot3SelfServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const HR_MODULES = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave', 'workforce_signature'];

    private Company $hrCo;      // Workforce + signature active
    private Company $plainCo;   // NO Workforce
    private CompanyRole $hrAdminRole;
    private User $admin;        // administrator of hrCo, ALSO an employee
    private User $member;       // plain member of hrCo, no HR record, no permission
    private User $worker;       // employee of hrCo
    private User $plainMember;  // member of plainCo
    private Employee $adminEmployee;
    private Employee $workerEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();
        Storage::fake('local');

        // Entitlement is jobdomain-wide ; ACTIVATION is per company (only hrCo activates Workforce).
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::HR_MODULES]);

        $this->hrCo = Company::create(['name' => 'HR Co', 'slug' => 'hr-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->plainCo = Company::create(['name' => 'Plain Co', 'slug' => 'plain-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->hrCo);
        $this->activateCompanyModules($this->plainCo);
        foreach (self::HR_MODULES as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(
                ['company_id' => $this->hrCo->id, 'module_key' => $m],
                ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT],
            );
        }
        $this->hrAdminRole = $this->setUpCompanyRbac($this->hrCo);
        $plainAdminRole = $this->setUpCompanyRbac($this->plainCo);

        $none = $this->role($this->hrCo, 'none', []);
        $this->admin = $this->member($this->hrCo, 'admin', $this->hrAdminRole);
        $this->member = $this->member($this->hrCo, 'member', $none);
        $this->worker = $this->member($this->hrCo, 'worker', $none);
        $this->plainMember = $this->member($this->plainCo, 'plain', $this->role($this->plainCo, 'none', []));
        $this->member($this->plainCo, 'plainadmin', $plainAdminRole);

        $this->adminEmployee = $this->employee($this->hrCo, ['user_id' => $this->admin->id]);
        $this->workerEmployee = $this->employee($this->hrCo, ['user_id' => $this->worker->id]);

        // Preconditions of the whole file.
        $this->assertTrue(ModuleGate::isActive($this->hrCo, 'workforce'));
        $this->assertTrue(ModuleGate::isActive($this->hrCo, 'workforce_signature'));
        $this->assertFalse(ModuleGate::isActive($this->plainCo, 'workforce'), 'plainCo must run WITHOUT Workforce');
        $this->assertTrue(ModuleGate::isActive($this->plainCo, 'core.documents'));
    }

    private function role(Company $company, string $key, array $permissions, bool $administrative = false): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => $key.'-'.uniqid(), 'name' => ucfirst($key), 'is_administrative' => $administrative]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    private function member(Company $company, string $name, ?CompanyRole $role, string $baseRole = 'user'): User
    {
        $user = User::factory()->create(['first_name' => ucfirst($name), 'last_name' => 'Test']);
        $company->memberships()->create(['user_id' => $user->id, 'role' => $baseRole, 'company_role_id' => $role?->id]);

        return $user;
    }

    private function employee(Company $company, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $company->id, 'employee_number' => 'E'.uniqid(),
            'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => uniqid().'@co.test', 'status' => 'active',
            'hire_date' => '2026-01-01',
        ], $attrs));
    }

    private function as(User $u, Company $company)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $company->id]);
    }

    private function payslip(Company $company, string $type, int $id, string $period, array $over = []): PublishedPayslip
    {
        $path = "payslips/{$company->id}/".uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return PublishedPayslip::create(array_merge([
            'company_id' => $company->id, 'document_subject_type' => $type, 'document_subject_id' => $id, 'period' => $period,
            'file_path' => $path, 'file_name' => "bulletin-{$period}.pdf", 'file_sha256' => hash('sha256', $path),
            'version_no' => 1, 'source' => PublishedPayslip::SOURCE_EXTERNAL, 'created_at' => now(),
        ], $over));
    }

    /** @return array{0: SignatureRequest, 1: SignatureSigner} */
    private function signature(Company $company, string $partyType, int $partyId, array $request = [], array $signer = []): array
    {
        $tpl = \App\Core\Documents\DocumentTemplate::create([
            'company_id' => $company->id, 'code' => 'tpl-'.uniqid(), 'name' => 'Contrat de travail',
            'subject_type' => 'employee', 'body_html' => '<p>x</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
        $doc = \App\Core\Documents\GeneratedDocument::create([
            'company_id' => $company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => 'employee', 'subject_id' => $partyId, 'generated_by' => $this->admin->id,
            'generated_at' => now(), 'file_path' => 'documents/generated/'.uniqid().'.pdf', 'file_size_bytes' => 10,
            'generation_snapshot' => ['x' => 1], 'signature_status' => 'none',
        ]);
        $req = SignatureRequest::create(array_merge([
            'company_id' => $company->id, 'generated_document_id' => $doc->id, 'subject_type' => 'employee', 'subject_id' => $partyId,
            'document_sha256' => str_repeat('a', 64), 'document_version' => 1, 'initiator_id' => $this->admin->id,
            'provider' => 'internal', 'signature_level' => 'simple', 'signing_order' => 'parallel',
            'status' => SignatureRequest::STATUS_PENDING, 'sent_at' => now(), 'expires_at' => now()->addDays(7),
        ], $request));
        $sig = SignatureSigner::create(array_merge([
            'company_id' => $company->id, 'signature_request_id' => $req->id, 'order' => 1, 'role' => 'employee',
            'party_type' => $partyType, 'party_id' => $partyId, 'name' => 'Signer', 'email' => uniqid().'@co.test',
            'status' => SignatureSigner::STATUS_INVITED, 'token_hash' => hash('sha256', uniqid()), 'invited_at' => now(),
        ], $signer));

        return [$req, $sig];
    }

    // ═══ Company WITHOUT Workforce ══════════════════════════════════════════

    public function test_company_without_workforce_keeps_core_self_service_and_announces_no_workforce_capability(): void
    {
        $space = $this->as($this->plainMember, $this->plainCo)->getJson('/api/profile/space')->assertOk();

        $space->assertJsonPath('capabilities.profile', true)->assertJsonPath('capabilities.documents', true)
            ->assertJsonPath('capabilities.payslips', true)->assertJsonPath('has_employee', false);
        foreach (['employment', 'time_clock', 'planning', 'leave', 'hr_documents', 'signatures'] as $cap) {
            $space->assertJsonPath("capabilities.{$cap}", false);
        }

        // Core self-service stays available with no permission at all.
        $this->as($this->plainMember, $this->plainCo)->getJson('/api/profile')->assertOk();
        $this->as($this->plainMember, $this->plainCo)->getJson('/api/profile/documents')->assertOk();
        $this->as($this->plainMember, $this->plainCo)->getJson('/api/profile/payslips')->assertOk()->assertJsonPath('payslips', []);
        $this->actingAs($this->plainMember)->getJson('/api/my-invitations')->assertOk();

        // The Workforce APIs do not exist here — which is why the client must follow the capabilities.
        $this->as($this->plainMember, $this->plainCo)->getJson('/api/workforce/me')->assertStatus(403);
        $this->as($this->plainMember, $this->plainCo)->getJson('/api/workforce/signature/me')->assertStatus(403);
    }

    public function test_personal_navigation_follows_modules_not_roles(): void
    {
        $keys = fn (Company $c, ?array $perms, string $level) => collect(NavBuilder::forCompany($c, $perms, $level))
            ->flatMap(fn ($g) => collect($g['items'] ?? [])->pluck('key'))->all();

        foreach (['management', 'operational'] as $level) {
            $plain = $keys($this->plainCo, [], $level);
            $this->assertContains('my-space', $plain, "Mon espace present without Workforce ({$level})");
            $this->assertContains('account-settings', $plain);
            $this->assertNotContains('workforce-me', $plain, 'no Workforce entry without the module');

            $hr = $keys($this->hrCo, [], $level);
            $this->assertContains('my-space', $hr);
            $this->assertContains('workforce-me', $hr);
        }
    }

    // ═══ Administrator who is ALSO an employee ══════════════════════════════

    public function test_administrator_who_is_also_an_employee_gets_his_own_space_and_nothing_more(): void
    {
        $mine = $this->payslip($this->hrCo, 'user', $this->admin->id, '2026-03');
        $theirs = $this->payslip($this->hrCo, 'user', $this->worker->id, '2026-03');
        [, $mySig] = $this->signature($this->hrCo, 'employee', $this->adminEmployee->id);
        [, $theirSig] = $this->signature($this->hrCo, 'employee', $this->workerEmployee->id);

        $space = $this->as($this->admin, $this->hrCo)->getJson('/api/profile/space')->assertOk();
        $space->assertJsonPath('has_employee', true)->assertJsonPath('employee_id', $this->adminEmployee->id)
            ->assertJsonPath('capabilities.employment', true)->assertJsonPath('capabilities.time_clock', true)
            ->assertJsonPath('capabilities.planning', true)->assertJsonPath('capabilities.leave', true)
            ->assertJsonPath('capabilities.signatures', true)
            ->assertJsonPath('counts.payslips', 1)->assertJsonPath('counts.signatures_to_sign', 1);

        // His own Workforce self-service works, as an employee.
        $this->as($this->admin, $this->hrCo)->getJson('/api/workforce/me')->assertOk()->assertJsonPath('employee.id', $this->adminEmployee->id);

        // Administrative permissions never widen SELF-service : only his payslips, only his signatures.
        $payslips = $this->as($this->admin, $this->hrCo)->getJson('/api/profile/payslips')->assertOk()->json('payslips');
        $this->assertSame([$mine->id], array_column($payslips, 'id'));
        $this->as($this->admin, $this->hrCo)->get("/api/profile/payslips/{$theirs->id}/download")->assertStatus(404);
        $this->as($this->admin, $this->hrCo)->get("/api/profile/payslips/{$mine->id}/download")->assertOk();

        $signatures = $this->as($this->admin, $this->hrCo)->getJson('/api/workforce/signature/me')->assertOk()->json('signatures');
        $this->assertSame([$mySig->id], array_column($signatures, 'id'));
        $this->as($this->admin, $this->hrCo)->postJson("/api/workforce/signature/me/{$theirSig->id}/open")->assertStatus(404);
    }

    // ═══ Account WITHOUT HR record ══════════════════════════════════════════

    public function test_account_without_hr_record_sees_no_fake_employment_and_none_is_created(): void
    {
        $before = Employee::withoutGlobalScopes()->count();

        $space = $this->as($this->member, $this->hrCo)->getJson('/api/profile/space')->assertOk();
        $space->assertJsonPath('has_employee', false)->assertJsonPath('employee_id', null)
            ->assertJsonPath('capabilities.employment', false)->assertJsonPath('capabilities.time_clock', false)
            ->assertJsonPath('capabilities.planning', false)->assertJsonPath('capabilities.leave', false)
            ->assertJsonPath('capabilities.documents', true)->assertJsonPath('capabilities.signatures', true);

        $this->as($this->member, $this->hrCo)->getJson('/api/workforce/me')->assertStatus(404);
        $this->as($this->member, $this->hrCo)->getJson('/api/workforce/signature/me')->assertOk()->assertJsonPath('signatures', []);
        $this->assertSame($before, Employee::withoutGlobalScopes()->count(), 'no HR record is ever created for an account');
    }

    public function test_terminated_employee_keeps_his_documents_but_no_live_workforce_tool(): void
    {
        $this->workerEmployee->forceFill(['status' => 'terminated'])->save();
        $slip = $this->payslip($this->hrCo, 'employee', $this->workerEmployee->id, '2026-01');

        $space = $this->as($this->worker, $this->hrCo)->getJson('/api/profile/space')->assertOk();
        $space->assertJsonPath('has_employee', true)->assertJsonPath('capabilities.employment', false)
            ->assertJsonPath('capabilities.time_clock', false)->assertJsonPath('counts.payslips', 1);
        $this->assertSame([$slip->id], array_column($this->as($this->worker, $this->hrCo)->getJson('/api/profile/payslips')->json('payslips'), 'id'));
    }

    // ═══ Payslips ═══════════════════════════════════════════════════════════

    public function test_payslips_published_before_the_account_was_linked_still_belong_to_the_person(): void
    {
        // An HR record without account receives a payslip ; the account is linked afterwards.
        $employee = $this->employee($this->hrCo);
        $early = $this->payslip($this->hrCo, 'employee', $employee->id, '2026-01');
        $newcomer = $this->member($this->hrCo, 'newcomer', $this->role($this->hrCo, 'none2', []));

        $this->assertSame([], $this->as($newcomer, $this->hrCo)->getJson('/api/profile/payslips')->json('payslips'));
        $this->as($newcomer, $this->hrCo)->get("/api/profile/payslips/{$early->id}/download")->assertStatus(404);

        $employee->forceFill(['user_id' => $newcomer->id])->save();
        $late = $this->payslip($this->hrCo, 'user', $newcomer->id, '2026-02');

        $list = $this->as($newcomer, $this->hrCo)->getJson('/api/profile/payslips')->assertOk()->json('payslips');
        $this->assertEqualsCanonicalizing([$early->id, $late->id], array_column($list, 'id'));
        $this->assertSame(['2026-02', '2026-01'], array_column($list, 'period'));
        $this->as($newcomer, $this->hrCo)->get("/api/profile/payslips/{$early->id}/download")->assertOk();

        // The HR view of the person shows the same complete series (payroll authority).
        $hr = $this->as($this->admin, $this->hrCo)->getJson("/api/company/people/employee-{$employee->id}/payslips")->assertOk()->json('payslips');
        $this->assertEqualsCanonicalizing([$early->id, $late->id], array_column($hr, 'id'));

        // Still nobody else's.
        $this->as($this->member, $this->hrCo)->get("/api/profile/payslips/{$early->id}/download")->assertStatus(404);
    }

    public function test_own_payslips_never_depend_on_documents_view_and_expose_versions_and_revocation(): void
    {
        $docViewer = $this->member($this->hrCo, 'docviewer', $this->role($this->hrCo, 'docviewer', ['documents.view']));
        $this->payslip($this->hrCo, 'user', $this->worker->id, '2026-01');

        // documents.view opens nobody's payslips through self-service.
        $this->assertSame([], $this->as($docViewer, $this->hrCo)->getJson('/api/profile/payslips')->assertOk()->json('payslips'));

        // The owner (no permission at all) sees the current version, a correction, and a revoked one.
        $this->payslip($this->hrCo, 'user', $this->worker->id, '2026-02', ['superseded_at' => now()]);
        $v2 = $this->payslip($this->hrCo, 'user', $this->worker->id, '2026-02', ['version_no' => 2, 'source' => PublishedPayslip::SOURCE_GENERATED]);
        $revoked = $this->payslip($this->hrCo, 'user', $this->worker->id, '2026-03', ['revoked_at' => now()]);

        $list = collect($this->as($this->worker, $this->hrCo)->getJson('/api/profile/payslips')->assertOk()->json('payslips'))->keyBy('period');
        $this->assertSame(['2026-03', '2026-02', '2026-01'], $list->keys()->all());
        $this->assertSame([$v2->id, 2, 'leezr_generated', 'available', true], [$list['2026-02']['id'], $list['2026-02']['version_no'], $list['2026-02']['source'], $list['2026-02']['status'], $list['2026-02']['can_download']]);
        $this->assertSame(['revoked', false], [$list['2026-03']['status'], $list['2026-03']['can_download']]);
        $this->assertSame('2026', $list['2026-01']['year']);

        $this->as($this->worker, $this->hrCo)->get("/api/profile/payslips/{$revoked->id}/download")->assertStatus(410);
        $this->as($this->worker, $this->hrCo)->get("/api/profile/payslips/{$v2->id}/download")->assertOk();
    }

    // ═══ Documents ═══════════════════════════════════════════════════════════

    public function test_documents_filed_before_the_account_was_linked_are_found_again_without_duplicate(): void
    {
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Fields\FieldDefinitionCatalog::sync();
        \App\Core\Documents\DocumentTypeCatalog::sync();
        $co = Company::create(['name' => 'Docs Co', 'slug' => 'docs-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($co);
        \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $co->id, 'module_key' => 'workforce'], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        $adminRole = $this->setUpCompanyRbac($co);
        $boss = $this->member($co, 'boss', $adminRole, 'owner');
        foreach (\App\Core\Documents\DocumentType::where('is_system', true)->get() as $i => $type) {
            \App\Core\Documents\DocumentTypeActivation::create(['company_id' => $co->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10]);
        }
        $pdf = fn (string $m) => \Illuminate\Http\UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\nL3[{$m}]\n".str_repeat('x', 48)."\n%%EOF");

        // 1. HR record WITHOUT account : the document is filed under the employee subject.
        $employee = $this->employee($co);
        $this->as($boss, $co)->postJson("/api/company/people/employee-{$employee->id}/documents/id_card", ['file' => $pdf('V1'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();

        // 2. The account is linked afterwards.
        $person = $this->member($co, 'person', $this->role($co, 'none', []));
        $employee->forceFill(['user_id' => $person->id])->save();

        // 3. The person finds HER document in her own space.
        $mine = collect($this->as($person, $co)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertNotNull($mine, 'id_card is offered to the person');
        $this->assertNotNull($mine['upload'] ?? null, 'the document filed before the account existed is still hers');
        $this->as($person, $co)->get('/api/profile/documents/id_card/download')->assertOk();

        // 4. She replaces it : still ONE current document for the person (no duplicate across subjects).
        $this->as($person, $co)->postJson('/api/profile/documents/id_card', ['file' => $pdf('V2'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();
        $current = \App\Core\Documents\MemberDocument::where('company_id', $co->id)
            ->where(fn ($q) => $q->where('user_id', $person->id)->orWhere(fn ($w) => $w->where('document_subject_type', 'employee')->where('document_subject_id', $employee->id)))
            ->count();
        $this->assertSame(1, $current, 'one current document per person and type');
        $this->assertCount(1, $this->as($boss, $co)->getJson("/api/company/people/employee-{$employee->id}/documents/id_card/versions")->assertOk()->json('versions'));
    }

    // ═══ Signatures ═════════════════════════════════════════════════════════

    public function test_my_signatures_expose_real_states_and_only_supported_actions(): void
    {
        $e = $this->workerEmployee->id;
        [, $toSign] = $this->signature($this->hrCo, 'employee', $e);
        [, $inProgress] = $this->signature($this->hrCo, 'employee', $e, ['status' => SignatureRequest::STATUS_PARTIALLY_SIGNED], ['status' => SignatureSigner::STATUS_SIGNED, 'acted_at' => now()]);
        Storage::disk(config('documents.generated_disk', 'local'))->put('signed/done.pdf', '%PDF signed');
        [, $signed] = $this->signature($this->hrCo, 'user', $this->worker->id, ['status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now(), 'signed_document_path' => 'signed/done.pdf'], ['status' => SignatureSigner::STATUS_SIGNED]);
        [, $declined] = $this->signature($this->hrCo, 'employee', $e, ['status' => SignatureRequest::STATUS_DECLINED], ['status' => SignatureSigner::STATUS_DECLINED]);
        [, $expired] = $this->signature($this->hrCo, 'employee', $e, ['expires_at' => now()->subDay()]);
        [, $cancelled] = $this->signature($this->hrCo, 'employee', $e, ['status' => SignatureRequest::STATUS_CANCELLED]);
        [, $external] = $this->signature($this->hrCo, 'employee', $e, ['provider' => 'external']);
        $this->signature($this->hrCo, 'employee', $e, ['status' => SignatureRequest::STATUS_DRAFT]); // never shown

        $rows = collect($this->as($this->worker, $this->hrCo)->getJson('/api/workforce/signature/me')->assertOk()->json('signatures'))->keyBy('id');

        $this->assertCount(7, $rows);
        $this->assertSame(['to_sign', true, false], [$rows[$toSign->id]['state'], $rows[$toSign->id]['can_sign'], $rows[$toSign->id]['can_download']]);
        $this->assertSame(['in_progress', false], [$rows[$inProgress->id]['state'], $rows[$inProgress->id]['can_sign']]);
        $this->assertSame(['signed', false, true], [$rows[$signed->id]['state'], $rows[$signed->id]['can_sign'], $rows[$signed->id]['can_download']]);
        $this->assertSame('declined', $rows[$declined->id]['state']);
        $this->assertSame(['expired', false], [$rows[$expired->id]['state'], $rows[$expired->id]['can_sign']]);
        $this->assertSame('cancelled', $rows[$cancelled->id]['state']);
        // A remote provider : visible, honest, with NO in-app action.
        $this->assertSame(['to_sign', true, false], [$rows[$external->id]['state'], $rows[$external->id]['handled_by_provider'], $rows[$external->id]['can_sign']]);
        $this->assertArrayNotHasKey('token_hash', $rows[$toSign->id]);

        // Supported actions only.
        $oldHash = $toSign->token_hash;
        $url = $this->as($this->worker, $this->hrCo)->postJson("/api/workforce/signature/me/{$toSign->id}/open")->assertOk()->json('url');
        $this->assertStringContainsString('/sign/', $url);
        $this->assertNotSame($oldHash, $toSign->fresh()->token_hash);
        $this->assertSame(hash('sha256', basename($url)), $toSign->fresh()->token_hash, 'the returned link is the one the existing ceremony accepts');
        $this->assertSame(SignatureSigner::STATUS_INVITED, $toSign->fresh()->status, 'opening signs nothing');

        foreach ([$external, $expired, $inProgress, $cancelled, $signed] as $closed) {
            $this->as($this->worker, $this->hrCo)->postJson("/api/workforce/signature/me/{$closed->id}/open")->assertStatus(422);
        }
        $this->as($this->worker, $this->hrCo)->get("/api/workforce/signature/me/{$signed->id}/download")->assertOk();
        $this->as($this->worker, $this->hrCo)->get("/api/workforce/signature/me/{$toSign->id}/download")->assertStatus(404);

        // Not mine : invisible and unusable, whatever the permission.
        $this->as($this->member, $this->hrCo)->postJson("/api/workforce/signature/me/{$toSign->id}/open")->assertStatus(404);
        $this->as($this->member, $this->hrCo)->get("/api/workforce/signature/me/{$signed->id}/download")->assertStatus(404);
        $this->as($this->worker, $this->hrCo)->getJson('/api/profile/space')->assertJsonPath('counts.signatures_to_sign', 2);
    }

    // ═══ Multi-company ══════════════════════════════════════════════════════

    public function test_switching_company_never_shows_the_other_companys_personal_data(): void
    {
        // `worker` (employee of hrCo) is also a plain member of plainCo.
        $this->plainCo->memberships()->create(['user_id' => $this->worker->id, 'role' => 'user', 'company_role_id' => CompanyRole::where('company_id', $this->plainCo->id)->where('is_administrative', false)->value('id')]);
        $inHr = $this->payslip($this->hrCo, 'user', $this->worker->id, '2026-04');
        $inPlain = $this->payslip($this->plainCo, 'user', $this->worker->id, '2026-05');
        [, $sig] = $this->signature($this->hrCo, 'employee', $this->workerEmployee->id);

        // Company A selected.
        $a = $this->as($this->worker, $this->hrCo);
        $this->assertSame([$inHr->id], array_column($a->getJson('/api/profile/payslips')->json('payslips'), 'id'));
        $this->as($this->worker, $this->hrCo)->getJson('/api/profile/space')->assertJsonPath('company_id', $this->hrCo->id)
            ->assertJsonPath('capabilities.employment', true)->assertJsonPath('counts.payslips', 1)->assertJsonPath('counts.signatures_to_sign', 1);
        $this->as($this->worker, $this->hrCo)->getJson('/api/workforce/me')->assertOk();

        // Company B selected : none of A's payslips, signatures, employment or contracts.
        $this->assertSame([$inPlain->id], array_column($this->as($this->worker, $this->plainCo)->getJson('/api/profile/payslips')->json('payslips'), 'id'));
        $this->as($this->worker, $this->plainCo)->get("/api/profile/payslips/{$inHr->id}/download")->assertStatus(404);
        $space = $this->as($this->worker, $this->plainCo)->getJson('/api/profile/space')->assertOk();
        $space->assertJsonPath('company_id', $this->plainCo->id)->assertJsonPath('has_employee', false)
            ->assertJsonPath('capabilities.employment', false)->assertJsonPath('capabilities.signatures', false)
            ->assertJsonPath('counts.payslips', 1)->assertJsonPath('counts.signatures_to_sign', 0);
        $this->as($this->worker, $this->plainCo)->getJson('/api/workforce/me')->assertStatus(403);
        $this->as($this->worker, $this->plainCo)->postJson("/api/workforce/signature/me/{$sig->id}/open")->assertStatus(403);

        // A company he does not belong to answers nothing.
        $stranger = Company::create(['name' => 'Stranger', 'slug' => 's-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->as($this->worker, $stranger)->getJson('/api/profile/space')->assertStatus(403);
    }

    public function test_document_requests_are_counted_per_company_and_per_person(): void
    {
        $type = \App\Core\Documents\DocumentType::first();
        $this->assertNotNull($type);
        $mk = fn (Company $c, User $u, string $status) => \App\Core\Documents\DocumentRequest::create([
            'company_id' => $c->id, 'user_id' => $u->id, 'document_type_id' => $type->id, 'status' => $status, 'requested_at' => now(),
        ]);
        $mk($this->hrCo, $this->worker, 'requested');
        $mk($this->hrCo, $this->member, 'requested');

        $this->as($this->worker, $this->hrCo)->getJson('/api/profile/space')->assertJsonPath('counts.document_requests', 1);
        $this->as($this->admin, $this->hrCo)->getJson('/api/profile/space')->assertJsonPath('counts.document_requests', 0);
    }
}
