<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Signature\Models\SignatureEvent;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\UseCases\FinalizeSignatureRequestUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-771 — a contract generated in Leezr is FILED AUTOMATICALLY in the collaborator's file : it is
 * visible there as soon as it is generated, and its signed version as soon as the signature is
 * really finalised — with no manual upload, no second copy, the original kept, the evidence kept.
 *
 * Every step goes through the REAL path : HR contract (HTTP) → generation (HTTP, real PDF by DomPDF)
 * → signature request (HTTP) → internal ceremony (public signer endpoints) → finalisation.
 */
class Adr771GeneratedContractFilingTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_contract', 'workforce_signature'];

    private Company $company;
    private Company $other;
    private User $owner;
    private User $otherOwner;
    private User $hrNoPay;        // HR : contracts + documents + signature, WITHOUT compensation_read
    private User $teamManager;    // workforce.view + documents, team scope
    private User $docViewer;      // documents.view only (Core DMS), no HR permission
    private User $person;         // account linked to $linked
    private Employee $managerEmp;
    private Employee $linked;     // HR record WITH account, report of the manager
    private Employee $accountless; // HR record WITHOUT account
    private Employee $outsider;   // not in the manager's team

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);

        [$this->company, $this->owner] = $this->makeCompany('adr771-a');
        [$this->other, $this->otherOwner] = $this->makeCompany('adr771-b');

        $hrPerms = ['workforce.view', 'workforce.manage', 'workforce.contracts', 'workforce_documents.view', 'workforce_documents.generate',
            'workforce_contract.view', 'workforce_contract.generate', 'workforce_signature.view', 'workforce_signature.request'];
        $this->hrNoPay = $this->memberWith($hrPerms, 'hr-nopay', true);
        $this->teamManager = $this->memberWith(['workforce.view', 'workforce_documents.view', 'workforce_signature.view'], 'manager');
        $this->docViewer = $this->memberWith(['documents.view', 'members.view'], 'docviewer');
        $this->person = $this->memberWith([], 'person');

        $this->managerEmp = $this->employee('MGR', $this->teamManager->id);
        $this->linked = $this->employee('LINK', $this->person->id, ['manager_id' => $this->managerEmp->id]);
        $this->accountless = $this->employee('NOACC', null, ['manager_id' => $this->managerEmp->id]);
        $this->outsider = $this->employee('OUT');

        DocumentTemplate::create([
            'company_id' => $this->company->id, 'code' => 'contrat-cdi', 'name' => 'Contrat CDI', 'subject_type' => 'contract',
            'body_html' => '<h1>Contrat de travail</h1><p>{{ employee.full_name }} — {{ contract.contract_type_label }} — {{ compensation.base_salary_formatted }}</p>',
            'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
    }

    /** @return array{0: Company, 1: User} */
    private function makeCompany(string $slug): array
    {
        // ADR-783 : un contrat exige l'adresse employeur ; ici données HISTORIQUES (colonnes), lues en repli.
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'address_street' => '1 rue Test', 'address_postal_code' => '75001', 'address_city' => 'Paris']);
        $this->activateCompanyModules($company);
        foreach (self::MODULES as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $adminRole = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);

        return [$company, $owner];
    }

    private function memberWith(array $permissions, string $key, bool $administrative = false): User
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => $key.'-'.uniqid(), 'name' => $key, 'is_administrative' => $administrative]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user = User::factory()->create(['first_name' => ucfirst($key), 'last_name' => 'Test']);
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $user;
    }

    private function employee(string $matricule, ?int $userId = null, array $over = [], ?Company $company = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'user_id' => $userId, 'first_name' => 'Emp', 'last_name' => $matricule,
            'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => $matricule, 'email' => strtolower($matricule).'@adr771.test',
        ], $over));
    }

    private function as(User $user, ?Company $company = null): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => ($company ?? $this->company)->id]);
    }

    /** HR contract through the real endpoint (owner : may set the salary). */
    private function hrContract(Employee $employee, array $over = []): EmploymentContract
    {
        $id = $this->as($this->owner)->postJson("/api/workforce/employees/{$employee->id}/contracts", array_merge([
            'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'start_date' => '2026-02-01', 'weekly_hours' => 35, 'base_salary_cents' => 271828,
        ], $over))->assertCreated()->json('id');

        return EmploymentContract::withoutGlobalScopes()->findOrFail($id);
    }

    /** Document generation through the real endpoint (real PDF). */
    private function generate(Employee $employee, ?User $actor = null): GeneratedDocument
    {
        $id = $this->as($actor ?? $this->owner)->postJson('/api/workforce/contract/generate', ['template_code' => 'contrat-cdi', 'employee_id' => $employee->id])
            ->assertCreated()->json('id');

        return GeneratedDocument::withoutGlobalScopes()->findOrFail($id);
    }

    private function requestSignature(GeneratedDocument $doc, Employee $employee, ?User $actor = null): SignatureRequest
    {
        $id = $this->as($actor ?? $this->owner)->postJson('/api/workforce/signature', [
            'generated_document_id' => $doc->id,
            'signers' => [['role' => 'employee', 'name' => 'Emp '.$employee->last_name, 'email' => $employee->email, 'order' => 1, 'party_type' => 'employee', 'party_id' => $employee->id]],
        ])->assertCreated()->json('id');

        return SignatureRequest::withoutGlobalScopes()->findOrFail($id);
    }

    /** The linked person runs the EXISTING ceremony from « Mes signatures ». */
    private function sign(SignatureRequest $req): string
    {
        $row = collect($this->as($this->person)->getJson('/api/workforce/signature/me')->assertOk()->json('signatures'))->firstWhere('request_id', $req->id);
        $token = basename($this->as($this->person)->postJson("/api/workforce/signature/me/{$row['id']}/open")->assertOk()->json('url'));
        $this->postJson("/api/public/signature/{$token}/sign")->assertOk();

        return $token;
    }

    private function fileRows(User $actor, Employee $employee): \Illuminate\Support\Collection
    {
        return collect($this->as($actor)->getJson("/api/workforce/documents/by-employee/{$employee->id}")->assertOk()->json('documents'));
    }

    // ═══ 1. Generated → filed immediately, by LINK ══════════════════════════

    public function test_generated_contract_is_in_the_collaborators_file_at_once_without_upload_or_copy(): void
    {
        foreach ([$this->linked, $this->accountless] as $employee) {
            $contract = $this->hrContract($employee);
            $before = GeneratedDocument::withoutGlobalScopes()->count();
            $doc = $this->generate($employee);

            // The document subject is the HR CONTRACT, which belongs to exactly one person.
            $this->assertSame(['contract', $contract->id, $this->company->id], [$doc->subject_type, (int) $doc->subject_id, $doc->company_id]);
            $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($doc->file_path));

            // Filed in the person's file at once — with or without an account — by reference.
            $row = $this->fileRows($this->owner, $employee)->firstWhere('id', $doc->id);
            $this->assertNotNull($row, 'the generated contract is listed in the collaborator file');
            $this->assertSame(['Contrat CDI', 'contract', $contract->id, 'none', false], [$row['label'], $row['subject_type'], $row['contract_id'], $row['signature_status'], $row['signed']['available']]);
            $this->assertSame($before + 1, GeneratedDocument::withoutGlobalScopes()->count(), 'no second copy');
            // The historical vault entry of a linked person (typeless row) is a LINK to the same file, never a copy.
            $vault = \App\Core\Documents\MemberDocument::where('company_id', $this->company->id)->whereNull('document_type_id')->pluck('file_path')->unique()->all();
            $this->assertEmpty(array_diff($vault, GeneratedDocument::withoutGlobalScopes()->pluck('file_path')->all()), 'the vault only references generated files, it copies nothing');

            // The file counter of the person file agrees.
            $this->assertSame(1, $this->as($this->owner)->getJson("/api/company/people/employee-{$employee->id}")->assertOk()->json('employment.documents.count'));

            // Download of the original = the stored bytes.
            $bytes = $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/download")->assertOk()->streamedContent();
            $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->file_path)), hash('sha256', $bytes));
        }

        // Another person's file does not list it.
        $this->assertCount(0, $this->fileRows($this->owner, $this->outsider));
    }

    public function test_the_person_finds_her_generated_contract_in_her_own_space(): void
    {
        $this->hrContract($this->linked);
        $doc = $this->generate($this->linked);
        $foreign = $this->generate(tap($this->outsider, fn ($e) => $this->hrContract($e)));

        $mine = collect($this->as($this->person)->getJson('/api/workforce/me/documents')->assertOk()->json('documents'));
        $this->assertSame([$doc->id], $mine->pluck('id')->all());

        // She downloads HER contract (no HR permission at all) ; never someone else's.
        $bytes = $this->as($this->person)->get("/api/workforce/me/generated-documents/{$doc->id}/download")->assertOk()->streamedContent();
        $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->file_path)), hash('sha256', $bytes));
        $this->as($this->person)->get("/api/workforce/me/generated-documents/{$foreign->id}/download")->assertStatus(404);
        $this->as($this->person)->get("/api/workforce/me/generated-documents/{$doc->id}/download?version=signed")->assertStatus(404); // not signed yet
        $this->as($this->person)->get("/api/workforce/documents/{$doc->id}/download")->assertStatus(403); // management route stays closed
    }

    // ═══ 2. Signed → definitive version, original and evidence kept ═════════

    public function test_signed_version_becomes_the_definitive_document_only_after_real_finalisation(): void
    {
        $contract = $this->hrContract($this->linked);
        $doc = $this->generate($this->linked);
        $originalSha = hash('sha256', Storage::disk('local')->get($doc->file_path));
        $req = $this->requestSignature($doc, $this->linked);

        // Requested, not signed : nothing claims a signature.
        $row = $this->fileRows($this->owner, $this->linked)->firstWhere('id', $doc->id);
        $this->assertSame(['pending', false, SignatureRequest::STATUS_PENDING], [$row['signature_status'], $row['signed']['available'], $row['signature_request']['status']]);
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/signed")->assertStatus(404);
        $this->assertFalse($this->as($this->owner)->getJson("/api/company/people/employee-{$this->linked->id}")->json('employment.onboarding.signed_contract'));

        $token = $this->sign($req);
        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);

        // The signed version is available AT THE SAME PLACE, as the definitive document.
        $row = $this->fileRows($this->owner, $this->linked)->firstWhere('id', $doc->id);
        $this->assertSame(['signed', true, $req->id, SignatureRequest::STATUS_COMPLETED], [$row['signature_status'], $row['signed']['available'], $row['signed']['signature_request_id'], $row['signature_request']['status']]);
        $signed = $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/signed")->assertOk()->streamedContent();
        $this->assertSame($req->signed_document_sha256, hash('sha256', $signed));
        $this->assertStringStartsWith('%PDF', $signed);

        // The ORIGINAL is never overwritten and stays downloadable.
        $this->assertSame($originalSha, hash('sha256', Storage::disk('local')->get($doc->fresh()->file_path)));
        $this->assertSame($originalSha, hash('sha256', $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/download")->assertOk()->streamedContent()));
        $this->assertNotSame($doc->file_path, $req->signed_document_path);
        $this->assertSame($originalSha, $req->document_sha256, 'the request is bound to the fingerprint of the original');

        // Evidence kept, for holders of the evidence permission only.
        $this->as($this->owner)->getJson("/api/workforce/signature/{$req->id}/evidence-package")->assertOk();
        $this->as($this->hrNoPay)->getJson("/api/workforce/signature/{$req->id}/evidence-package")->assertStatus(403);
        $this->as($this->person)->getJson("/api/workforce/signature/{$req->id}/evidence-package")->assertStatus(403);

        // The person gets the signed version from her own space ; the onboarding step is now done.
        $mine = $this->as($this->person)->get("/api/workforce/me/generated-documents/{$doc->id}/download?version=signed")->assertOk()->streamedContent();
        $this->assertSame($req->signed_document_sha256, hash('sha256', $mine));
        $this->assertTrue($this->as($this->owner)->getJson("/api/company/people/employee-{$this->linked->id}")->json('employment.onboarding.signed_contract'), 'a SIGNED generated contract completes the onboarding step');

        // HR contract state and document signature are two DISTINCT states : signing activated nothing.
        $this->assertSame(EmploymentContract::STATUS_DRAFT, $contract->fresh()->status);

        // Replays change nothing : same file, same state, no extra event, no second artifact.
        $files = count(Storage::disk('local')->allFiles('documents/signed'));
        $events = SignatureEvent::withoutGlobalScopes()->where('signature_request_id', $req->id)->count();
        $this->postJson("/api/public/signature/{$token}/sign")->assertStatus(422);
        $again = app(FinalizeSignatureRequestUseCase::class)->execute($req->fresh());
        $this->assertSame([SignatureRequest::STATUS_COMPLETED, $req->signed_document_path, $req->signed_document_sha256], [$again->status, $again->signed_document_path, $again->signed_document_sha256]);
        $this->assertSame($files, count(Storage::disk('local')->allFiles('documents/signed')), 'no second signed artifact');
        $this->assertSame($events, SignatureEvent::withoutGlobalScopes()->where('signature_request_id', $req->id)->count(), 'no duplicated event');
        $this->assertSame('signed', $doc->fresh()->signature_status);
    }

    public function test_activating_the_hr_contract_never_marks_a_document_signed(): void
    {
        $contract = $this->hrContract($this->linked);
        $doc = $this->generate($this->linked);

        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->linked->id}/contracts/{$contract->id}/activate")->assertOk();

        $this->assertSame(EmploymentContract::STATUS_ACTIVE, $contract->fresh()->status);
        $this->assertSame('none', $doc->fresh()->signature_status);
        $row = $this->fileRows($this->owner, $this->linked)->firstWhere('id', $doc->id);
        $this->assertSame(['none', false, null], [$row['signature_status'], $row['signed']['available'], $row['signature_request']]);
        $this->assertFalse($this->as($this->owner)->getJson("/api/company/people/employee-{$this->linked->id}")->json('employment.onboarding.signed_contract'));
    }

    public function test_a_declined_signature_never_yields_a_signed_version(): void
    {
        $this->hrContract($this->linked);
        $doc = $this->generate($this->linked);
        $req = $this->requestSignature($doc, $this->linked);
        $row = collect($this->as($this->person)->getJson('/api/workforce/signature/me')->json('signatures'))->firstWhere('request_id', $req->id);
        $token = basename($this->as($this->person)->postJson("/api/workforce/signature/me/{$row['id']}/open")->json('url'));
        $this->postJson("/api/public/signature/{$token}/decline", ['reason' => 'Clause contestee'])->assertOk();

        $file = $this->fileRows($this->owner, $this->linked)->firstWhere('id', $doc->id);
        $this->assertSame(['declined', false], [$file['signature_status'], $file['signed']['available']]);
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/signed")->assertStatus(404);
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/download")->assertOk(); // the original is still there
    }

    // ═══ 3. Versioning ══════════════════════════════════════════════════════

    public function test_regenerating_the_same_contract_returns_the_original_and_writes_nothing(): void
    {
        // Existing rule : generation is idempotent per (template version, contract). Asking again
        // returns the SAME document — the original is never replaced, and no second file is written.
        $this->hrContract($this->linked);
        $first = $this->generate($this->linked);
        $firstSha = hash('sha256', Storage::disk('local')->get($first->file_path));
        $files = count(Storage::disk('local')->allFiles('documents/generated'));
        $this->travel(1)->minutes();
        $second = $this->generate($this->linked);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($firstSha, hash('sha256', Storage::disk('local')->get($first->fresh()->file_path)), 'the original is untouched');
        $this->assertSame($files, count(Storage::disk('local')->allFiles('documents/generated')), 'no orphan file written by the second request');
        $this->assertSame([$first->id], $this->fileRows($this->owner, $this->linked)->pluck('id')->all());
    }

    // ═══ 4. Confidentiality & download rights ═══════════════════════════════

    public function test_download_rights_follow_scope_and_compensation_confidentiality(): void
    {
        $this->hrContract($this->linked);
        $this->hrContract($this->outsider);
        $doc = $this->generate($this->linked);
        $outsiderDoc = $this->generate($this->outsider);
        $req = $this->requestSignature($doc, $this->linked);
        $this->sign($req);

        // The generated contract embeds the salary : without `workforce.compensation_read`, an actor
        // who is NOT the person herself sees the document in the file but cannot open it.
        foreach ([$this->hrNoPay, $this->teamManager] as $actor) {
            $row = $this->fileRows($actor, $this->linked)->firstWhere('id', $doc->id);
            $this->assertNotNull($row);
            $this->assertFalse($row['can_download'], 'listed, not downloadable without compensation access');
            $this->assertStringNotContainsString('2 718', json_encode($row));
            $this->as($actor)->get("/api/workforce/documents/{$doc->id}/download")->assertStatus(403);
            $this->as($actor)->get("/api/workforce/documents/{$doc->id}/signed")->assertStatus(403);
            $this->as($actor)->get("/api/workforce/signature/{$req->id}/download")->assertStatus(403);
        }

        // Team scope : the manager never reaches the file of someone outside his team.
        $this->as($this->teamManager)->getJson("/api/workforce/documents/by-employee/{$this->outsider->id}")->assertStatus(403);
        $this->as($this->teamManager)->get("/api/workforce/documents/{$outsiderDoc->id}/download")->assertStatus(403);

        // Core `documents.view` (uploaded documents) opens nothing here.
        $this->as($this->docViewer)->getJson("/api/workforce/documents/by-employee/{$this->linked->id}")->assertStatus(403);
        $this->as($this->docViewer)->get("/api/workforce/documents/{$doc->id}/download")->assertStatus(403);
        $this->as($this->docViewer)->get("/api/workforce/documents/{$doc->id}/signed")->assertStatus(403);

        // Owner (compensation access) : everything. The person : her own, original and signed.
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/download")->assertOk();
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/signed")->assertOk();
        $this->as($this->owner)->get("/api/workforce/signature/{$req->id}/download")->assertOk();
        $this->as($this->person)->get("/api/workforce/me/generated-documents/{$doc->id}/download")->assertOk();
        $this->as($this->person)->get("/api/workforce/me/generated-documents/{$outsiderDoc->id}/download")->assertStatus(404);

        // Another company : nothing resolves.
        $this->as($this->otherOwner, $this->other)->getJson("/api/workforce/documents/by-employee/{$this->linked->id}")->assertStatus(404);
        $this->as($this->otherOwner, $this->other)->get("/api/workforce/documents/{$doc->id}/download")->assertStatus(404);
        $this->as($this->otherOwner, $this->other)->get("/api/workforce/documents/{$doc->id}/signed")->assertStatus(404);
    }

    public function test_generation_never_returns_the_salary_to_an_actor_without_compensation_access(): void
    {
        $this->hrContract($this->linked);

        // An HR profile without pay access may generate the contract and request its signature…
        $res = $this->as($this->hrNoPay)->postJson('/api/workforce/contract/generate', ['template_code' => 'contrat-cdi', 'employee_id' => $this->linked->id])->assertCreated();
        $this->assertStringNotContainsString('271828', $res->getContent());
        $this->assertStringNotContainsString('2 718', $res->getContent());
        $doc = GeneratedDocument::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->requestSignature($doc, $this->linked, $this->hrNoPay);

        // …and the registry never serialises the salary for that profile either.
        $registry = $this->as($this->hrNoPay)->getJson("/api/workforce/documents?employee_id={$this->linked->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('271828', $registry);
        $this->assertStringNotContainsString('2 718', $registry);
        $this->assertStringNotContainsString('271828', $this->as($this->hrNoPay)->getJson("/api/workforce/documents/{$doc->id}")->assertOk()->getContent());

        // The stored document itself does carry it (it is the real contract).
        $this->assertStringContainsString('271828', json_encode($doc->generation_snapshot));
    }

    // ═══ ADR-772 — real signature state everywhere, and end of collaboration ═══

    private function registryStatus(Employee $employee, GeneratedDocument $doc): ?string
    {
        $rows = $this->as($this->owner)->getJson("/api/workforce/documents?employee_id={$employee->id}")->assertOk()->json('data');

        return collect($rows)->firstWhere('id', $doc->id)['signature_status'] ?? null;
    }

    public function test_adr772_hr_registry_shows_the_real_signature_state(): void
    {
        // Declined.
        $contract = $this->hrContract($this->linked);
        $doc = $this->generate($this->linked);
        $req = $this->requestSignature($doc, $this->linked);
        $this->assertSame('pending', $this->registryStatus($this->linked, $doc));
        $row = collect($this->as($this->person)->getJson('/api/workforce/signature/me')->json('signatures'))->firstWhere('request_id', $req->id);
        $token = basename($this->as($this->person)->postJson("/api/workforce/signature/me/{$row['id']}/open")->json('url'));
        $this->postJson("/api/public/signature/{$token}/decline", ['reason' => 'Non'])->assertOk();

        $this->assertSame('declined', $this->registryStatus($this->linked, $doc), 'registry');
        $this->assertSame('declined', $this->as($this->owner)->getJson("/api/workforce/documents/{$doc->id}")->assertOk()->json('signature_status'), 'detail');
        $this->assertSame('declined', collect($this->as($this->person)->getJson('/api/workforce/me/documents')->json('documents'))->firstWhere('id', $doc->id)['signature_status'], 'self-service');

        // A new request after the refusal : the state follows the LATEST request, then the real signature.
        $second = $this->requestSignature($doc, $this->linked);
        $this->assertSame('pending', $this->registryStatus($this->linked, $doc));
        $this->sign($second);
        $this->assertSame('signed', $this->registryStatus($this->linked, $doc));

        // Cancelled.
        $this->hrContract($this->outsider);
        $docCancelled = $this->generate($this->outsider);
        $reqCancelled = $this->requestSignature($docCancelled, $this->outsider);
        $this->as($this->owner)->postJson("/api/workforce/signature/{$reqCancelled->id}/cancel", ['reason' => 'Erreur'])->assertOk();
        $this->assertSame('cancelled', $this->registryStatus($this->outsider, $docCancelled));

        // Expired (scheduled command).
        $this->hrContract($this->accountless);
        $docExpired = $this->generate($this->accountless);
        $reqExpired = $this->requestSignature($docExpired, $this->accountless);
        SignatureRequest::withoutGlobalScopes()->whereKey($reqExpired->id)->update(['expires_at' => now()->subHour()]);
        $this->artisan('workforce:signature-expire')->assertSuccessful();
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $reqExpired->fresh()->status);
        $this->assertSame('expired', $this->registryStatus($this->accountless, $docExpired));

        // A document status never changes the HR contract.
        $this->assertSame(EmploymentContract::STATUS_DRAFT, $contract->fresh()->status);
        // Another company sees none of it.
        $this->as($this->otherOwner, $this->other)->getJson("/api/workforce/documents/{$doc->id}")->assertStatus(404);
    }

    public function test_adr773_a_draft_contract_can_be_abandoned_by_an_authorised_person(): void
    {
        $contract = $this->hrContract($this->linked); // draft
        $doc = $this->generate($this->linked);
        $req = $this->requestSignature($doc, $this->linked);
        $url = "/api/workforce/employees/{$this->linked->id}/contracts/{$contract->id}/cancel";

        // Permissions and tenant.
        $this->as($this->teamManager)->postJson($url, ['reason' => 'x'])->assertStatus(403);
        $this->as($this->otherOwner, $this->other)->postJson($url, ['reason' => 'x'])->assertStatus(404);
        $this->as($this->owner)->postJson($url, [])->assertStatus(422); // a reason is required
        $this->assertSame(EmploymentContract::STATUS_DRAFT, $contract->fresh()->status);

        $this->as($this->hrNoPay)->postJson($url, ['reason' => 'Embauche annulee'])->assertOk()->assertJsonPath('status', 'cancelled');

        // Abandoned, never activated ; kept in the history.
        $this->assertSame(['cancelled', false], [$contract->fresh()->status, (bool) $contract->fresh()->is_current]);
        $this->assertContains($contract->id, array_column($this->as($this->owner)->getJson("/api/workforce/employees/{$this->linked->id}/contracts")->assertOk()->json(), 'id'));
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'contract.cancelled', 'target_id' => (string) $contract->id]);
        $this->assertDatabaseMissing('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'contract.activated', 'target_id' => (string) $contract->id]);

        // Generated document and signature stay traceable ; the pending signature is cancelled by the engine.
        $row = $this->fileRows($this->owner, $this->linked)->firstWhere('id', $doc->id);
        $this->assertSame(['cancelled', $contract->id], [$row['signature_status'], $row['contract_id']]);
        $this->assertSame(SignatureRequest::STATUS_CANCELLED, $req->fresh()->status);
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/download")->assertOk();
        $this->assertGreaterThan(0, SignatureEvent::withoutGlobalScopes()->where('signature_request_id', $req->id)->count());

        // Terminal : not twice, not activable ; an ACTIVE contract is not « cancelled », it follows termination.
        $this->as($this->owner)->postJson($url, ['reason' => 'encore'])->assertStatus(422);
        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->linked->id}/contracts/{$contract->id}/activate")->assertStatus(422);
        $active = $this->hrContract($this->linked); // same dates : the abandoned draft does not block a new contract
        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->linked->id}/contracts/{$active->id}/activate")->assertOk();
        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->linked->id}/contracts/{$active->id}/cancel", ['reason' => 'x'])->assertStatus(422);
        $this->assertSame(EmploymentContract::STATUS_ACTIVE, $active->fresh()->status);
    }

    public function test_adr773_a_draft_contract_never_blocks_the_end_of_collaboration(): void
    {
        $draft = $this->hrContract($this->linked);
        $doc = $this->generate($this->linked);
        $url = "/api/workforce/employees/{$this->linked->id}/terminate";
        $payrollLines = \App\Core\Workforce\PayrollLine::withoutGlobalScopes()->count();

        $this->as($this->teamManager)->postJson($url, ['reason' => 'x'])->assertStatus(403);
        $this->as($this->otherOwner, $this->other)->postJson($url, ['reason' => 'x'])->assertStatus(404);

        // The employee leaves ; the draft is ABANDONED — never activated, never « terminated ».
        $this->as($this->owner)->postJson($url, ['reason' => 'Fin de periode'])->assertOk();
        $this->assertSame('terminated', $this->linked->fresh()->status);
        $this->assertSame(['cancelled', false], [$draft->fresh()->status, (bool) $draft->fresh()->is_current]);
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'contract.cancelled', 'target_id' => (string) $draft->id]);
        $this->assertDatabaseMissing('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'contract.activated']);

        // History and documents kept ; no effect on payroll.
        $this->assertContains($draft->id, array_column($this->as($this->owner)->getJson("/api/workforce/employees/{$this->linked->id}/contracts")->json(), 'id'));
        $this->as($this->owner)->get("/api/workforce/documents/{$doc->id}/download")->assertOk();
        $this->assertSame($payrollLines, \App\Core\Workforce\PayrollLine::withoutGlobalScopes()->count());
        $this->assertSame(0, EmploymentContract::withoutGlobalScopes()->where('employee_id', $this->linked->id)->where('is_current', true)->count());

        // An ACTIVE contract follows its own rule : terminated with the employee.
        $active = $this->hrContract($this->outsider);
        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->outsider->id}/contracts/{$active->id}/activate")->assertOk();
        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->outsider->id}/terminate", ['reason' => 'Depart'])->assertOk();
        $this->assertSame(EmploymentContract::STATUS_TERMINATED, $active->fresh()->status);

        // Already terminated : explicit refusal, not a 500. No contract at all : recorded.
        $this->as($this->owner)->postJson($url, ['reason' => 'encore'])->assertStatus(422);
        $this->as($this->owner)->postJson("/api/workforce/employees/{$this->accountless->id}/terminate", ['reason' => 'Depart'])->assertOk();
    }

    // ═══ ADR-773 — the registry FILTERS and COUNTS on the state it displays ═══

    public function test_adr773_registry_filter_counters_and_pagination_use_the_displayed_state(): void
    {
        $doc = [];
        $make = function (string $state, ?Employee $employee = null) use (&$doc) {
            $employee ??= $this->employee('S-'.strtoupper($state));
            $this->hrContract($employee);
            $doc[$state] = $this->generate($employee);

            return $employee;
        };
        $force = fn (SignatureRequest $r, string $status) => SignatureRequest::withoutGlobalScopes()->whereKey($r->id)->update(['status' => $status]);

        $make('none');
        $e = $make('pending');
        $this->requestSignature($doc['pending'], $e);
        $e = $make('in_progress');
        $force($this->requestSignature($doc['in_progress'], $e), SignatureRequest::STATUS_PARTIALLY_SIGNED);
        $make('signed', $this->linked);
        $this->sign($this->requestSignature($doc['signed'], $this->linked));
        $e = $make('declined');
        $force($this->requestSignature($doc['declined'], $e), SignatureRequest::STATUS_DECLINED);
        $e = $make('expired');
        $force($this->requestSignature($doc['expired'], $e), SignatureRequest::STATUS_EXPIRED);
        $e = $make('cancelled');
        $cancelled = $this->requestSignature($doc['cancelled'], $e);
        $this->as($this->owner)->postJson("/api/workforce/signature/{$cancelled->id}/cancel", ['reason' => 'x'])->assertOk();
        $e = $make('failed');
        $force($this->requestSignature($doc['failed'], $e), SignatureRequest::STATUS_FAILED);

        // The stored projection is misleading on purpose : the filter must not rely on it.
        $this->assertSame('pending', $doc['declined']->fresh()->signature_status);
        $this->assertSame('pending', $doc['expired']->fresh()->signature_status);

        $states = ['none', 'pending', 'in_progress', 'signed', 'declined', 'expired', 'cancelled', 'failed'];
        foreach ($states as $state) {
            $page = $this->as($this->owner)->getJson("/api/workforce/documents?signature_status={$state}&per_page=50")->assertOk();
            $this->assertSame([$doc[$state]->id], array_column($page->json('data'), 'id'), "filter « {$state} »");
            $this->assertSame([$state], array_column($page->json('data'), 'signature_status'), "displayed state of the filtered row « {$state} »");
            $this->assertSame(1, $page->json('total'), "pagination total « {$state} »");
        }

        // Counters : same semantics, and they add up to the unfiltered total.
        $stats = $this->as($this->owner)->getJson('/api/workforce/documents/statistics')->assertOk()->json();
        foreach ($states as $state) {
            $this->assertSame(1, (int) ($stats['by_signature_status'][$state] ?? 0), "counter « {$state} »");
        }
        $this->assertSame(count($states), array_sum($stats['by_signature_status']));
        $this->assertSame(count($states), $this->as($this->owner)->getJson('/api/workforce/documents?per_page=50')->json('total'));

        // Filter + pagination stay consistent (page size 1 over an unfiltered list keeps every row once).
        $seen = [];
        for ($p = 1; $p <= count($states); $p++) {
            $seen[] = $this->as($this->owner)->getJson("/api/workforce/documents?per_page=1&page={$p}")->json('data.0.id');
        }
        $this->assertEqualsCanonicalizing(array_map(fn ($d) => $d->id, $doc), $seen);

        // Permissions and tenant.
        $this->as($this->docViewer)->getJson('/api/workforce/documents?signature_status=declined')->assertStatus(403);
        $other = $this->as($this->otherOwner, $this->other)->getJson('/api/workforce/documents?signature_status=declined')->assertOk();
        $this->assertSame(0, $other->json('total'));
        $this->assertSame(0, array_sum($this->as($this->otherOwner, $this->other)->getJson('/api/workforce/documents/statistics')->json('by_signature_status') ?: []));
    }
}
