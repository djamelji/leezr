<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\ImageProcessor;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipImportItem;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-770 (M7 LOT 4) — TRANSVERSAL acceptance of the DMS (M0→M6). One journey per domain, chained
 * end to end through the REAL paths (HTTP → job → database → read back), with REAL synthetic PDF
 * files (text layer, multipage, ZIP, scanned image). Each journey checks the FINAL state, not an
 * isolated method — the existing suites already cover the units.
 */
class M7Lot4DmsRecetteTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const HR_MODULES = ['workforce', 'workforce_documents', 'workforce_signature'];

    private Company $company;
    private Company $other;
    private User $owner;
    private User $member;          // plain member, no permission
    private $memberMembership;
    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::HR_MODULES]);

        [$this->company, $this->owner] = $this->makeCompany('lot4-a');
        [$this->other, $this->otherOwner] = $this->makeCompany('lot4-b');

        $this->member = User::factory()->create(['first_name' => 'Marie', 'last_name' => 'Membre']);
        $this->memberMembership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => 'user', 'company_role_id' => $this->role([], 'none')->id]);
    }

    /** @return array{0: Company, 1: User} */
    private function makeCompany(string $slug): array
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        foreach (self::HR_MODULES as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $adminRole = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::firstOrCreate(['company_id' => $company->id, 'document_type_id' => $type->id], ['enabled' => true, 'order' => $i * 10]);
        }

        return [$company, $owner];
    }

    private function role(array $permissions, string $key, bool $administrative = false, ?Company $company = null): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => ($company ?? $this->company)->id, 'key' => $key.'-'.uniqid(), 'name' => $key, 'is_administrative' => $administrative]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    private function memberWith(array $permissions, string $key, bool $administrative = false): User
    {
        $user = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $this->role($permissions, $key, $administrative)->id]);

        return $user;
    }

    private function employee(string $matricule, ?int $userId = null, ?Company $company = null, array $over = []): Employee
    {
        return Employee::withoutGlobalScopes()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'user_id' => $userId,
            'first_name' => 'Emp', 'last_name' => $matricule, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $matricule, 'email' => strtolower($matricule).'@lot4.test',
        ], $over));
    }

    private function as(User $user, ?Company $company = null): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => ($company ?? $this->company)->id]);
    }

    /** A REAL PDF with a selectable text layer : one page per entry. */
    private function textPdfBytes(array $pages): string
    {
        $pdf = new Fpdi();
        foreach ($pages as $lines) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            foreach ($lines as $line) {
                $pdf->Cell(0, 10, $line, 0, 1);
            }
        }

        return $pdf->Output('S');
    }

    private function textPdf(array $pages, string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->textPdfBytes($pages));
    }

    /** A REAL ZIP archive holding real PDFs. @param array<string, string> $entries name => bytes */
    private function zip(array $entries, string $name = 'lot.zip'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lot4zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $bytes) {
            $zip->addFromString($entry, $bytes);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function standardTypeCode(): string
    {
        $code = DocumentType::where('is_system', true)->where('scope', DocumentType::SCOPE_COMPANY_USER)->pluck('code')
            ->first(fn ($c) => DocumentConfidentiality::category($c) === DocumentConfidentiality::CAT_STANDARD);
        $this->assertNotNull($code, 'a standard (non confidential) personal document type exists');

        return $code;
    }

    private function importBatch(array $files): PayslipImportBatch
    {
        $id = $this->as($this->owner)->post('/api/company/payslip-imports', ['files' => $files], ['Accept' => 'application/json'])
            ->assertCreated()->json('batch.id');

        return PayslipImportBatch::withoutGlobalScopes()->findOrFail($id);
    }

    // ═══ 1. VAULT ═══════════════════════════════════════════════════════════

    public function test_vault_upload_versioning_confidentiality_download_and_removal(): void
    {
        $mid = $this->memberMembership->id;
        $v1 = $this->textPdf([['Carte identite', 'VERSION-1']], 'id-v1.pdf');
        $v2 = $this->textPdf([['Carte identite', 'VERSION-2']], 'id-v2.pdf');
        $std = $this->standardTypeCode();

        // Upload → stored, hashed, bound to the subject.
        $this->as($this->owner)->postJson("/api/company/members/{$mid}/documents/id_card", ['file' => $v1, 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $doc = MemberDocument::where('company_id', $this->company->id)->where('user_id', $this->member->id)->firstOrFail();
        $firstPath = $doc->file_path;
        $firstHash = $doc->file_sha256;
        $this->assertNotNull($firstHash);
        Storage::disk('local')->assertExists($firstPath);

        // Replacement is NON destructive : one current document, the previous file kept as a version.
        $this->as($this->owner)->postJson("/api/company/members/{$mid}/documents/id_card", ['file' => $v2, 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $doc->refresh();
        $this->assertNotSame($firstHash, $doc->file_sha256);
        $this->assertSame(1, MemberDocument::where('company_id', $this->company->id)->where('user_id', $this->member->id)->count());
        $this->assertNotSame($firstPath, $doc->file_path, 'a replacement never reuses the path of the previous file');
        $this->assertSame($firstHash, hash('sha256', Storage::disk('local')->get($firstPath)), 'the previous bytes are still on disk');
        $versions = $this->as($this->owner)->getJson("/api/company/members/{$mid}/documents/id_card/versions")->assertOk()->json('versions');
        $this->assertCount(1, $versions);

        // Download returns the CURRENT bytes ; the version download returns the previous ones.
        $current = $this->as($this->owner)->get("/api/company/members/{$mid}/documents/id_card/download")->assertOk();
        $this->assertSame($doc->file_sha256, hash('sha256', $current->streamedContent()));
        $old = $this->as($this->owner)->get("/api/company/members/{$mid}/documents/id_card/versions/{$versions[0]['id']}/download")->assertOk();
        $this->assertSame($firstHash, hash('sha256', $old->streamedContent()));

        // Two employees WITHOUT account, same document type, REAL PDFs : each keeps HER OWN file.
        [$e1, $e2] = [$this->employee('V-ONE'), $this->employee('V-TWO')];
        $this->as($this->owner)->postJson("/api/company/people/employee-{$e1->id}/documents/id_card", ['file' => $this->textPdf([['Carte de V-ONE']]), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $one = MemberDocument::where('document_subject_type', 'employee')->where('document_subject_id', $e1->id)->firstOrFail();
        $this->as($this->owner)->postJson("/api/company/people/employee-{$e2->id}/documents/id_card", ['file' => $this->textPdf([['Carte de V-TWO']]), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $two = MemberDocument::where('document_subject_type', 'employee')->where('document_subject_id', $e2->id)->firstOrFail();
        $this->assertNotSame($one->file_path, $two->file_path, 'two persons never share a stored file');
        $this->assertSame($one->file_sha256, hash('sha256', Storage::disk('local')->get($one->file_path)), 'the first person\'s file is intact after the second upload');
        $this->assertSame($one->file_sha256, hash('sha256', $this->as($this->owner)->get("/api/company/people/employee-{$e1->id}/documents/id_card/download")->assertOk()->streamedContent()));
        $this->assertSame($two->file_sha256, hash('sha256', $this->as($this->owner)->get("/api/company/people/employee-{$e2->id}/documents/id_card/download")->assertOk()->streamedContent()));

        // Confidentiality by category, for someone else's documents.
        $this->as($this->owner)->postJson("/api/company/members/{$mid}/documents/{$std}", ['file' => $this->textPdf([['Standard']])])->assertOk();
        $viewer = $this->memberWith(['members.view', 'documents.view'], 'viewer');
        $ref = "user-{$this->member->id}";
        $this->as($viewer)->get("/api/company/people/{$ref}/documents/{$std}/download")->assertOk();
        $this->as($viewer)->get("/api/company/people/{$ref}/documents/id_card/download")->assertStatus(403);
        $this->as($viewer)->getJson("/api/company/people/{$ref}/documents/id_card/versions")->assertStatus(403);
        $listed = collect($this->as($viewer)->getJson("/api/company/people/{$ref}/documents")->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertStringNotContainsString('id-v2.pdf', json_encode($listed), 'identity metadata is redacted for a plain viewer');

        // The owner of the document always reaches her own, whatever the category.
        $this->as($this->member)->get('/api/profile/documents/id_card/download')->assertOk();
        // No access at all without a facet, and never from another company.
        $this->as($this->memberWith([], 'nobody'))->get("/api/company/people/{$ref}/documents/id_card/download")->assertStatus(403);
        $this->as($this->otherOwner, $this->other)->get("/api/company/members/{$mid}/documents/id_card/download")->assertStatus(404);

        // Removal : authorised for the document manager only, and traced.
        $this->as($viewer)->deleteJson("/api/company/members/{$mid}/documents/{$std}")->assertStatus(403);
        $this->as($this->owner)->deleteJson("/api/company/members/{$mid}/documents/{$std}")->assertSuccessful();
        $stdType = DocumentType::where('code', $std)->value('id');
        $this->assertSame(0, MemberDocument::where('company_id', $this->company->id)->where('user_id', $this->member->id)->where('document_type_id', $stdType)->whereNotNull('file_path')->count());
        $this->as($this->owner)->get("/api/company/members/{$mid}/documents/{$std}/download")->assertStatus(404);
        // The identity document is untouched by the removal of another one.
        $this->as($this->owner)->get("/api/company/members/{$mid}/documents/id_card/download")->assertOk();
    }

    // ═══ 2. REQUESTS ════════════════════════════════════════════════════════

    public function test_request_lifecycle_submit_reject_resubmit_approve_replace_and_accountless_employee(): void
    {
        $mid = $this->memberMembership->id;
        $typeId = DocumentType::where('code', 'id_card')->value('id');

        // Creation (with message and due date).
        $this->as($this->owner)->postJson('/api/company/document-requests', [
            'user_id' => $this->member->id, 'document_type_code' => 'id_card', 'message' => 'Merci', 'due_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();
        $req = DocumentRequest::where('company_id', $this->company->id)->where('user_id', $this->member->id)->where('document_type_id', $typeId)->firstOrFail();
        $this->assertSame('requested', $req->status);

        // The person sees the request in HER space and submits through the SELF path.
        $mine = collect($this->as($this->member)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertSame(['requested', 'Merci'], [$mine['request_status'], $mine['request_message']]);
        $this->as($this->member)->post('/api/profile/documents/id_card', ['file' => $this->textPdf([['Recto flou']]), 'expires_at' => now()->addYear()->toDateString()], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame('submitted', $req->fresh()->status);

        // Rejection with a reason : the reason reaches the person, the history is kept.
        $this->as($this->owner)->putJson("/api/company/members/{$mid}/documents/id_card/review", ['status' => 'rejected', 'review_note' => 'Photo illisible'])->assertOk();
        $mine = collect($this->as($this->member)->getJson('/api/profile/documents')->json('documents'))->firstWhere('code', 'id_card');
        $this->assertSame(['rejected', 'Photo illisible'], [$mine['request_status'], $mine['request_review_note']]);

        // Resubmission, then approval (the validated version is recorded).
        $this->as($this->member)->post('/api/profile/documents/id_card', ['file' => $this->textPdf([['Recto net']]), 'expires_at' => now()->addYear()->toDateString()], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame('submitted', $req->fresh()->status);
        $this->as($this->owner)->putJson("/api/company/members/{$mid}/documents/id_card/review", ['status' => 'approved'])->assertOk();
        $this->assertSame('approved', $req->fresh()->status);
        $approved = DocumentRequestEvent::where('document_request_id', $req->id)->where('event', 'approved')->firstOrFail();
        $this->assertSame(MemberDocument::where('user_id', $this->member->id)->where('document_type_id', $typeId)->value('file_sha256'), $approved->metadata['validated_sha256'] ?? null);

        // Replacement after approval : a NEW validation is required ; nothing was lost on the way.
        $this->as($this->member)->post('/api/profile/documents/id_card', ['file' => $this->textPdf([['Nouvelle carte']]), 'expires_at' => now()->addYears(2)->toDateString()], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame('submitted', $req->fresh()->status);
        $this->assertSame(['requested', 'submitted', 'rejected', 'submitted', 'approved', 'submitted'],
            DocumentRequestEvent::where('document_request_id', $req->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(1, DocumentRequest::where('company_id', $this->company->id)->where('user_id', $this->member->id)->where('document_type_id', $typeId)->count());
        $this->assertCount(2, $this->as($this->owner)->getJson("/api/company/members/{$mid}/documents/id_card/versions")->assertOk()->json('versions'));

        // Employee WITHOUT account : request and deposit by subject, no artificial user.
        $emp = $this->employee('NOACC');
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => "employee-{$emp->id}", 'document_type_code' => 'id_card'])->assertCreated();
        $this->as($this->owner)->postJson("/api/company/people/employee-{$emp->id}/documents/id_card", ['file' => $this->textPdf([['Sans compte']]), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $empReq = DocumentRequest::where('company_id', $this->company->id)->where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->firstOrFail();
        $this->assertSame(['submitted', null], [$empReq->status, $empReq->user_id]);

        // Cancellation : only a request still waiting for the person ; a submitted one must be reviewed instead.
        $this->as($this->owner)->putJson("/api/company/document-requests/{$empReq->id}/cancel")->assertStatus(422);
        $std = $this->standardTypeCode();
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => "employee-{$emp->id}", 'document_type_code' => $std])->assertCreated();
        $pending = DocumentRequest::where('company_id', $this->company->id)->where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->where('status', 'requested')->firstOrFail();
        $this->as($this->owner)->putJson("/api/company/document-requests/{$pending->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertDatabaseHas('document_request_events', ['document_request_id' => $pending->id, 'event' => 'cancelled']);
        $this->as($this->otherOwner, $this->other)->putJson("/api/company/document-requests/{$req->id}/cancel")->assertStatus(404);
    }

    // ═══ 3. CAMPAIGNS ═══════════════════════════════════════════════════════

    public function test_campaign_targeting_snapshot_dedup_jobs_tracking_reminder_and_compliance(): void
    {
        $linked = $this->employee('C-LINK', $this->member->id);   // same PERSON as the member account
        $alone = $this->employee('C-ALONE');                       // no account
        $this->employee('C-OUT', null, null, ['status' => 'terminated']);
        $typeId = DocumentType::where('code', 'id_card')->value('id');
        $std = $this->standardTypeCode();

        // Preview : a person is counted once (account + HR record are ONE recipient).
        $preview = $this->as($this->owner)->postJson('/api/company/document-campaigns/preview', ['targeting' => ['mode' => 'all']])->assertOk()->json();
        $expected = $preview['total'] ?? $preview['count'] ?? $preview['recipients_total'] ?? null;
        $this->assertNotNull($expected, 'preview exposes a total: '.json_encode(array_keys($preview)));

        // Creation freezes the snapshot ; the launch job (sync connection) creates the individual requests.
        $id = $this->as($this->owner)->postJson('/api/company/document-campaigns', [
            'document_type_code' => 'id_card', 'targeting' => ['mode' => 'all'], 'message' => 'Campagne annuelle', 'due_date' => now()->addDays(10)->toDateString(),
        ])->assertCreated()->json('campaign.id');
        $campaign = DocumentCampaign::findOrFail($id);
        $this->assertSame(DocumentCampaign::STATUS_ACTIVE, $campaign->status);
        $this->assertSame($expected, $campaign->recipients_total);
        $recipients = DocumentCampaignRecipient::where('document_campaign_id', $id)->get();
        $this->assertSame($campaign->recipients_total, $recipients->count());
        $this->assertSame(0, $recipients->whereNull('document_request_id')->count(), 'every recipient has a real request');

        // One request per PERSON and type : the linked person has one, the accountless employee has one, the terminated none.
        $personRequests = DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', $typeId)
            ->where(fn ($q) => $q->where('user_id', $this->member->id)->orWhere(fn ($w) => $w->where('document_subject_type', 'employee')->where('document_subject_id', $linked->id)))->count();
        $this->assertSame(1, $personRequests, 'no duplicate between user:{id} and employee:{id} for one person');
        $aloneReq = DocumentRequest::where('company_id', $this->company->id)->where('document_subject_type', 'employee')->where('document_subject_id', $alone->id)->firstOrFail();
        $this->assertNull($aloneReq->user_id);

        // Snapshot : a person hired AFTER the launch is not silently added.
        $this->employee('C-LATE');
        $this->as($this->owner)->postJson("/api/company/document-campaigns/{$id}/launch")->assertOk();
        $this->assertSame($campaign->recipients_total, DocumentCampaignRecipient::where('document_campaign_id', $id)->count());

        // Deduplication : relaunch and a second campaign of the same type reuse the active requests.
        $before = DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', $typeId)->count();
        (new \App\Jobs\Documents\LaunchDocumentCampaignJob($id))->handle(app(\App\Core\Documents\Services\DocumentCampaignService::class));
        $second = $this->as($this->owner)->postJson('/api/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'employees', 'employee_ids' => [$alone->id]]])->assertCreated()->json('campaign.id');
        $this->assertSame($before, DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', $typeId)->count());
        $this->assertSame($aloneReq->id, DocumentCampaignRecipient::where('document_campaign_id', $second)->value('document_request_id'));

        // Department / person targeting through the REAL HTTP path (the keys must reach the resolver).
        $dept = \App\Core\Workforce\Department::create(['company_id' => $this->company->id, 'name' => 'Quai']);
        $inDept = $this->employee('C-DEPT', null, null, ['department_id' => $dept->id]);
        $foreignDept = \App\Core\Workforce\Department::create(['company_id' => $this->other->id, 'name' => 'Ailleurs']);
        $this->employee('X-DEPT', null, $this->other, ['department_id' => $foreignDept->id]);
        $this->assertSame(1, $this->as($this->owner)->postJson('/api/company/document-campaigns/preview', ['targeting' => ['mode' => 'department', 'department_ids' => [$dept->id]]])->assertOk()->json('total'));
        $this->assertSame(0, $this->as($this->owner)->postJson('/api/company/document-campaigns/preview', ['targeting' => ['mode' => 'department', 'department_ids' => [$foreignDept->id]]])->assertOk()->json('total'), 'a department of another company targets nobody');
        $this->as($this->owner)->postJson('/api/company/document-campaigns/preview', ['targeting' => ['mode' => 'department']])->assertStatus(422);
        $deptCampaign = $this->as($this->owner)->postJson('/api/company/document-campaigns', ['document_type_code' => $std, 'targeting' => ['mode' => 'department', 'department_ids' => [$dept->id]]])->assertCreated();
        $deptCampaign->assertJsonPath('campaign.recipients_total', 1);
        $this->assertSame([['employee', $inDept->id]], DocumentCampaignRecipient::where('document_campaign_id', $deptCampaign->json('campaign.id'))->get()->map(fn ($r) => [$r->document_subject_type, $r->document_subject_id])->all());
        $this->assertSame(1, $this->as($this->owner)->postJson('/api/company/document-campaigns/preview', ['targeting' => ['mode' => 'persons', 'person_refs' => ["employee-{$alone->id}"]]])->assertOk()->json('total'));

        // Tracking follows the real state of the requests.
        $partition = fn () => $this->as($this->owner)->getJson("/api/company/document-campaigns/{$id}")->assertOk()->json('partition');
        $start = $partition();
        $this->as($this->owner)->postJson("/api/company/people/employee-{$alone->id}/documents/id_card", ['file' => $this->textPdf([['Piece']]), 'expires_at' => now()->addYear()->toDateString()])->assertOk();
        $this->assertNotSame($start, $partition(), 'a deposit moves the campaign partition');
        $rows = $this->as($this->owner)->getJson("/api/company/document-campaigns/{$id}/recipients")->assertOk()->json();
        $this->assertSame($campaign->recipients_total, $rows['total']);
        $this->assertContains("employee-{$alone->id}", array_column($rows['items'], 'person_ref'));

        // Reminder of a pending request : traced once per call, on the request itself.
        $pending = DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', $typeId)->where('status', 'requested')->firstOrFail();
        $requestedAt = $pending->requested_at?->toIso8601String();
        $notified = $this->as($this->owner)->putJson("/api/company/document-requests/{$pending->id}/remind")->assertOk()->json('notified');
        $this->assertSame($pending->user_id !== null, $notified, 'the answer says whether somebody was actually notified');
        $this->assertSame(1, DocumentRequestEvent::where('document_request_id', $pending->id)->where('event', 'reminded')->count());
        $this->assertNotNull($pending->fresh()->last_reminded_at);
        $this->assertSame($requestedAt, $pending->fresh()->requested_at?->toIso8601String(), 'a reminder never rewrites the date of the request');

        // Compliance reads the same truth, accountless employees included.
        DocumentTypeActivation::where('company_id', $this->company->id)->where('document_type_id', $typeId)->update(['required_override' => true]);
        $byType = collect($this->as($this->owner)->getJson('/api/company/documents/compliance')->assertOk()->json('by_type'))->firstWhere('code', 'id_card');
        $this->assertNotNull($byType, 'a required personal document is part of the compliance');
        $this->assertGreaterThanOrEqual(3, $byType['total'], 'accountless employees count in the denominator');
        $this->assertGreaterThanOrEqual(1, $byType['pending_validation'], 'the deposit of the accountless employee awaits validation');
        $this->assertSame($byType['total'], $byType['valid'] + $byType['missing'] + $byType['requested'] + $byType['pending_validation'] + $byType['rejected'] + $byType['expired'], 'every slot is in exactly one state');

        // Cancelling the campaign cancels what is still pending — not what was already provided.
        $this->as($this->owner)->putJson("/api/company/document-campaigns/{$id}/cancel")->assertOk();
        $this->assertSame(DocumentCampaign::STATUS_CANCELLED, $campaign->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertNotSame('cancelled', $aloneReq->fresh()->status);

        // Isolation : the other company sees neither the campaign nor a recipient, and received no request.
        $this->as($this->otherOwner, $this->other)->getJson("/api/company/document-campaigns/{$id}")->assertStatus(404);
        $this->assertSame(0, DocumentRequest::withoutGlobalScopes()->where('company_id', $this->other->id)->count());
    }

    // ═══ 4. EXPIRY ══════════════════════════════════════════════════════════

    public function test_expiry_renewal_and_reminders_through_the_scheduled_commands_are_idempotent(): void
    {
        $mid = $this->memberMembership->id;
        $typeId = DocumentType::where('code', 'id_card')->value('id');
        CompanyDocumentSetting::updateOrCreate(['company_id' => $this->company->id], [
            'auto_renew_enabled' => true, 'renew_days_before' => 30, 'auto_remind_enabled' => true, 'remind_after_days' => 7,
            'reminder_policy' => ['expiration_before_days' => [7], 'expiration_at' => false, 'expiration_after_days' => [], 'request_due_before_days' => [], 'request_due_after_days' => []],
        ]);
        // Another company with the SAME situation but automation OFF : nothing may happen there.
        CompanyDocumentSetting::updateOrCreate(['company_id' => $this->other->id], ['auto_renew_enabled' => false, 'renew_days_before' => 30, 'auto_remind_enabled' => false, 'remind_after_days' => 7]);
        $otherMember = User::factory()->create();
        $otherMid = $this->other->memberships()->create(['user_id' => $otherMember->id, 'role' => 'user', 'company_role_id' => $this->role([], 'none', false, $this->other)->id])->id;
        $this->as($this->otherOwner, $this->other)->postJson("/api/company/members/{$otherMid}/documents/id_card", ['file' => $this->textPdf([['B']]), 'expires_at' => now()->addDays(5)->toDateString()])->assertOk();

        // A real document, uploaded and approved, expiring in 5 days ; one far in the future.
        $this->as($this->owner)->postJson("/api/company/members/{$mid}/documents/id_card", ['file' => $this->textPdf([['Expire bientot']]), 'expires_at' => now()->addDays(5)->toDateString()])->assertOk();
        $this->as($this->owner)->putJson("/api/company/members/{$mid}/documents/id_card/review", ['status' => 'approved'])->assertOk();
        $far = $this->employee('FAR');
        $this->as($this->owner)->postJson("/api/company/people/employee-{$far->id}/documents/id_card", ['file' => $this->textPdf([['Loin']]), 'expires_at' => now()->addYears(3)->toDateString()])->assertOk();
        $requestsBefore = DocumentRequest::withoutGlobalScopes()->count();
        $eventsBefore = DocumentRequestEvent::withoutGlobalScopes()->count();

        // Dry run : reports, changes nothing.
        $this->artisan('documents:check-expiration', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame([$requestsBefore, $eventsBefore], [DocumentRequest::withoutGlobalScopes()->count(), DocumentRequestEvent::withoutGlobalScopes()->count()]);

        // Renewal : the expiring document re-opens its request once.
        $this->artisan('documents:auto-renew')->assertSuccessful();
        $req = DocumentRequest::where('company_id', $this->company->id)->where('user_id', $this->member->id)->where('document_type_id', $typeId)->firstOrFail();
        $this->assertContains($req->status, ['requested', 'to_replace']);
        $this->assertSame(1, DocumentRequestEvent::where('document_request_id', $req->id)->where('event', 'renewed')->count());

        // Reminder tier J-7 fires once.
        $this->artisan('documents:auto-remind')->assertSuccessful();
        $reminded = DocumentRequestEvent::where('company_id', $this->company->id)->where('event', 'reminded')->count();
        $this->assertGreaterThanOrEqual(1, $reminded);

        // The three scheduled entry points replayed : ONE effect only (idempotence of the whole day).
        $snapshot = fn () => [DocumentRequest::withoutGlobalScopes()->count(), DocumentRequestEvent::withoutGlobalScopes()->where('event', 'renewed')->count(), DocumentRequestEvent::withoutGlobalScopes()->where('event', 'reminded')->count()];
        $after = $snapshot();
        foreach (['documents:check-expiration', 'documents:auto-renew', 'documents:auto-remind', 'documents:check-expiration'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }
        $this->assertSame($after, $snapshot(), 'replaying the scheduled commands creates nothing new');

        // The current document and its history survive the renewal ; the far one and the other company are untouched.
        $this->as($this->owner)->get("/api/company/members/{$mid}/documents/id_card/download")->assertOk();
        $this->assertSame(0, DocumentRequestEvent::withoutGlobalScopes()->where('company_id', $this->other->id)->whereIn('event', ['renewed', 'reminded'])->count());
        $this->assertSame(0, DocumentRequestEvent::where('company_id', $this->company->id)->where('event', 'renewed')
            ->whereIn('document_request_id', DocumentRequest::where('document_subject_type', 'employee')->where('document_subject_id', $far->id)->pluck('id'))->count());

        // The person sees the renewal in her own space and renews : the previous version is kept.
        $mine = collect($this->as($this->member)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertContains($mine['request_status'], ['requested', 'to_replace']);
        $this->as($this->member)->post('/api/profile/documents/id_card', ['file' => $this->textPdf([['Renouvelee']]), 'expires_at' => now()->addYears(5)->toDateString()], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame('submitted', $req->fresh()->status);
        $this->assertCount(1, $this->as($this->owner)->getJson("/api/company/members/{$mid}/documents/id_card/versions")->assertOk()->json('versions'));
        $this->artisan('documents:check-expiration')->assertSuccessful();
        $this->assertSame($after[1], DocumentRequestEvent::withoutGlobalScopes()->where('event', 'renewed')->count(), 'a renewed document is not renewed again');
    }

    // ═══ 5. EXTERNAL PAYSLIPS ═══════════════════════════════════════════════

    public function test_payslip_import_text_multipage_zip_review_publish_correction_revocation_and_self_service(): void
    {
        $a = $this->employee('A100', $this->member->id);   // linked to the member account
        $b = $this->employee('B200');                       // no account
        $c = $this->employee('C300');
        $d = $this->employee('D400');
        $this->employee('A100', null, $this->other);        // same matricule in ANOTHER company

        // Real files : a multipage text PDF + a ZIP holding another real PDF + an unidentified page.
        $main = $this->textPdf([
            ['Bulletin de paie', 'Matricule: A100', 'Periode: 2026-01', 'Page 1/2'],
            ['Matricule: A100', 'Periode: 2026-01', 'Page 2/2'],
            ['Bulletin de paie', 'Matricule: B200', 'Periode: 2026-01', 'Page 1/1'],
            ['Bulletin de paie', 'sans identifiant lisible'],
        ], 'janvier.pdf');
        $zip = $this->zip(['c300.pdf' => $this->textPdfBytes([['Bulletin de paie', 'Matricule: C300', 'Periode: 2026-01', 'Page 1/1']])]);

        $batch = $this->importBatch([$main, $zip]);
        $this->assertSame(PayslipImportBatch::STATUS_REVIEW, $batch->status);
        $items = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batch->id)->orderBy('id')->get();

        // Segmentation + matching : A (2 pages) and C (from the ZIP) are certain. The page WITHOUT identifier that
        // follows B is never attached silently : the whole segment is held for review (M5.2 rule).
        $byMatricule = $items->keyBy('extracted_matricule');
        $this->assertSame(['A100', 'B200', 'C300'], $byMatricule->keys()->sort()->values()->all(), 'text PDF, multipage and ZIP are all read');
        $this->assertSame([$a->id, 'matched', 2], [$byMatricule['A100']->matched_employee_id, $byMatricule['A100']->state, count($byMatricule['A100']->page_numbers)]);
        $this->assertSame([$c->id, 'matched'], [$byMatricule['C300']->matched_employee_id, $byMatricule['C300']->state]);
        $held = $byMatricule['B200'];
        $this->assertSame([null, 'review', 'no_matricule_page'], [$held->matched_employee_id, $held->state, $held->review_reason]);
        $this->assertFalse($held->isPublishable());
        // Publishing now publishes ONLY the certain ones — never the held segment.
        $this->as($this->owner)->postJson("/api/company/payslip-imports/{$batch->id}/publish")->assertOk();
        $this->assertSame(2, PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // Review centre : the reviewer splits the unidentified page off, then assigns both explicitly.
        $review = $this->as($this->owner)->getJson("/api/company/payslip-imports/{$batch->id}/items")->assertOk()->json('items');
        $this->assertCount($items->count(), $review);
        $this->as($this->owner)->get("/api/company/payslip-imports/{$batch->id}/items/{$held->id}/preview")->assertOk();
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$held->id}/split", ['pages' => [$held->page_numbers[1]]])->assertSuccessful();
        $orphan = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batch->id)->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($held->id, $orphan->id);
        $this->assertFalse($held->fresh()->isPublishable(), 'a reshaped segment must be confirmed again');
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$held->id}/assign", ['employee_id' => $b->id])->assertOk();
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$orphan->id}/period", ['period' => '2026-01'])->assertSuccessful();
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$orphan->id}/assign", ['employee_id' => $d->id])->assertOk();
        // An employee of another company can never be assigned.
        $foreign = Employee::withoutGlobalScopes()->where('company_id', $this->other->id)->value('id');
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$orphan->id}/assign", ['employee_id' => $foreign])->assertStatus(422);

        // Publication : exactly one payslip per person, each file is the reconstituted PDF of ITS pages.
        $this->as($this->owner)->postJson("/api/company/payslip-imports/{$batch->id}/publish")->assertOk();
        $published = PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->get();
        $this->assertCount(4, $published);
        $this->assertSame(0, PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->other->id)->count());
        $ofA = $published->first(fn ($p) => in_array([$p->document_subject_type, $p->document_subject_id], [['user', $this->member->id], ['employee', $a->id]], true));
        $this->assertNotNull($ofA, 'A100 received its payslip');
        $bytes = Storage::disk('local')->get($ofA->file_path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $tmp = tempnam(sys_get_temp_dir(), 'lot4p');
        file_put_contents($tmp, $bytes);
        $check = new Fpdi();
        $this->assertSame(2, $check->setSourceFile($tmp), 'the published file holds the two pages of A100 and nothing else');
        @unlink($tmp);
        // Publishing twice publishes nothing more.
        $this->as($this->owner)->postJson("/api/company/payslip-imports/{$batch->id}/publish")->assertOk();
        $this->assertSame(4, PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // Self-service : the linked person sees HER payslip only, and downloads it.
        $mine = $this->as($this->member)->getJson('/api/profile/payslips')->assertOk()->json('payslips');
        $this->assertSame([$ofA->id], array_column($mine, 'id'));
        $this->assertSame(['2026-01', 1, 'available', true], [$mine[0]['period'], $mine[0]['version_no'], $mine[0]['status'], $mine[0]['can_download']]);
        $this->as($this->member)->get("/api/profile/payslips/{$ofA->id}/download")->assertOk();
        $notMine = $published->firstWhere('document_subject_id', $b->id);
        $this->as($this->member)->get("/api/profile/payslips/{$notMine->id}/download")->assertStatus(404);

        // Correction : a second import for the SAME person and period publishes version 2 ; version 1 is kept, superseded.
        $fix = $this->importBatch([$this->textPdf([['Bulletin rectificatif', 'Matricule: A100', 'Periode: 2026-01', 'Page 1/1']], 'rectif.pdf')]);
        $this->as($this->owner)->postJson("/api/company/payslip-imports/{$fix->id}/publish")->assertOk();
        $series = PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->where('period', '2026-01')
            ->where('document_subject_type', $ofA->document_subject_type)->where('document_subject_id', $ofA->document_subject_id)->orderBy('version_no')->get();
        $this->assertSame([1, 2], $series->pluck('version_no')->all());
        $this->assertNotNull($series[0]->superseded_at);
        Storage::disk('local')->assertExists($series[0]->file_path);
        $mine = $this->as($this->member)->getJson('/api/profile/payslips')->assertOk()->json('payslips');
        $this->assertSame([[$series[1]->id, 2]], array_map(fn ($p) => [$p['id'], $p['version_no']], $mine), 'the person sees the current version');

        // Revocation : traced, visible as revoked, no longer downloadable by anyone through self-service.
        $this->as($this->memberWith(['documents.view', 'documents.manage'], 'docadmin', true))->putJson("/api/company/published-payslips/{$series[1]->id}/revoke", ['reason' => 'Erreur'])->assertStatus(403);
        $this->as($this->owner)->putJson("/api/company/published-payslips/{$series[1]->id}/revoke", ['reason' => 'Erreur de montant'])->assertOk();
        $mine = $this->as($this->member)->getJson('/api/profile/payslips')->assertOk()->json('payslips');
        $this->assertSame(['revoked', false], [$mine[0]['status'], $mine[0]['can_download']]);
        $this->as($this->member)->get("/api/profile/payslips/{$series[1]->id}/download")->assertStatus(410);
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'payslip.revoked']);
        // The other persons' payslips are untouched.
        $this->assertSame(3, PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->whereNull('revoked_at')->whereNull('superseded_at')->count());
    }

    public function test_scanned_payslip_goes_through_ocr_when_available_and_never_publishes_without_review(): void
    {
        $a = $this->employee('A100');
        $images = app(ImageProcessor::class);
        $ocr = (bool) ($images->findTesseract() && $images->findMagick());

        // A page that is ONLY an image : no text layer at all.
        $small = imagecreatetruecolor(360, 60);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        imagestring($small, 5, 8, 6, 'Matricule: A100', imagecolorallocate($small, 0, 0, 0));
        imagestring($small, 5, 8, 32, 'Periode: 2026-03', imagecolorallocate($small, 0, 0, 0));
        $big = imagecreatetruecolor(1800, 300);
        imagecopyresampled($big, $small, 0, 0, 0, 0, 1800, 300, 360, 60);
        $png = tempnam(sys_get_temp_dir(), 'lot4').'.png';
        imagepng($big, $png);
        $pdf = new Fpdi();
        $pdf->AddPage();
        $pdf->Image($png, 10, 20, 180);
        @unlink($png);

        $batch = $this->importBatch([UploadedFile::fake()->createWithContent('scan.pdf', $pdf->Output('S'))]);
        $item = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batch->id)->firstOrFail();

        // With or without the OCR toolchain the outcome is SAFE : review, no automatic match, nothing published.
        $this->assertSame(PayslipImportItem::S_REVIEW, $item->state);
        $this->assertNull($item->matched_employee_id);
        $this->as($this->owner)->postJson("/api/company/payslip-imports/{$batch->id}/publish")->assertOk();
        $this->assertSame(0, PublishedPayslip::withoutGlobalScopes()->count());
        if ($ocr) {
            $this->assertNotNull($item->extracted_matricule, 'the local OCR toolchain read the scan');
        } else {
            $this->assertNull($item->extracted_matricule, 'without OCR the page stays unidentified');
        }

        // The reviewer confirms by hand → publishable, published once.
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$item->id}/period", ['period' => '2026-03'])->assertSuccessful();
        $this->as($this->owner)->putJson("/api/company/payslip-imports/{$batch->id}/items/{$item->id}/assign", ['employee_id' => $a->id])->assertOk();
        $this->as($this->owner)->postJson("/api/company/payslip-imports/{$batch->id}/publish")->assertOk();
        $this->assertSame(1, PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->where('period', '2026-03')->count());
    }

    // ═══ 6. SIGNATURES ══════════════════════════════════════════════════════

    private function generatedDocument(int $employeeId): \App\Core\Documents\GeneratedDocument
    {
        $tpl = \App\Core\Documents\DocumentTemplate::create([
            'company_id' => $this->company->id, 'code' => 'tpl-'.uniqid(), 'name' => 'Contrat de travail',
            'subject_type' => 'employee', 'body_html' => '<p>{{ company.name }}</p>', 'variables_schema' => [], 'version' => 1, 'is_active' => true,
        ]);
        $path = 'documents/generated/'.$this->company->id.'/2026/11/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat</h1><p>'.uniqid().'</p>')->output());

        return \App\Core\Documents\GeneratedDocument::create([
            'company_id' => $this->company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => 'employee', 'subject_id' => $employeeId, 'generated_by' => $this->owner->id,
            'generated_at' => now(), 'file_path' => $path, 'file_size_bytes' => Storage::disk('local')->size($path),
            'generation_snapshot' => ['x' => 1], 'signature_status' => 'none',
        ]);
    }

    private function requestSignature(Employee $employee, User $account): array
    {
        $doc = $this->generatedDocument($employee->id);
        $res = $this->as($this->owner)->postJson('/api/workforce/signature', [
            'generated_document_id' => $doc->id,
            'signers' => [['role' => 'employee', 'name' => 'Marie Membre', 'email' => $account->email, 'order' => 1, 'party_type' => 'employee', 'party_id' => $employee->id]],
        ])->assertCreated();

        return [SignatureRequest::withoutGlobalScopes()->findOrFail($res->json('id')), $doc];
    }

    public function test_internal_signature_request_ceremony_finalisation_evidence_refusal_and_expiry(): void
    {
        $employee = $this->employee('SIG1', $this->member->id);

        // Request created through the API by the real engine ; invitation sent to the signer.
        [$req, $doc] = $this->requestSignature($employee, $this->member);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $req->status);
        $this->assertNotSame('external', $req->provider);
        $signer = $req->signers()->firstOrFail();
        $this->assertSame(SignatureSigner::STATUS_INVITED, $signer->status);

        // The signer finds it in « Mes signatures » and opens the EXISTING ceremony.
        $row = collect($this->as($this->member)->getJson('/api/workforce/signature/me')->assertOk()->json('signatures'))->firstWhere('request_id', $req->id);
        $this->assertSame(['to_sign', true, false], [$row['state'], $row['can_sign'], $row['can_download']]);
        $token = basename($this->as($this->member)->postJson("/api/workforce/signature/me/{$row['id']}/open")->assertOk()->json('url'));

        // The ceremony : the page shows the document, serves the exact bytes, then records the signature.
        $this->getJson("/api/public/signature/{$token}")->assertOk();
        $served = $this->get("/api/public/signature/{$token}/document")->assertOk();
        $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->file_path)), hash('sha256', $served->streamedContent()), 'the signer reads the document that will be signed');
        $this->postJson("/api/public/signature/{$token}/sign")->assertOk();

        // Finalisation : completed ONLY with a sealed document and verifiable evidence.
        $req->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->status);
        $this->assertNotNull($req->signed_document_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($req->signed_document_path)), $req->signed_document_sha256);
        $this->assertSame('signed', $doc->fresh()->signature_status);
        $this->as($this->owner)->getJson("/api/workforce/signature/{$req->id}/verify")->assertOk();
        $evidence = $this->as($this->owner)->getJson("/api/workforce/signature/{$req->id}/evidence")->assertOk();
        $this->assertStringContainsString($req->document_sha256, $evidence->getContent());

        // The link is single use ; the signer downloads the signed copy from her space ; the source is intact.
        $this->postJson("/api/public/signature/{$token}/sign")->assertStatus(422);
        $signedCopy = $this->as($this->member)->get("/api/workforce/signature/me/{$row['id']}/download")->assertOk();
        $this->assertStringStartsWith('%PDF', $signedCopy->streamedContent());
        Storage::disk('local')->assertExists($doc->file_path);
        // Nobody else : not a plain member, not another company.
        $this->as($this->memberWith([], 'stranger'))->get("/api/workforce/signature/me/{$row['id']}/download")->assertStatus(404);
        $this->as($this->otherOwner, $this->other)->getJson("/api/workforce/signature/{$req->id}")->assertStatus(404);

        // Refusal : recorded with its reason, the request ends declined, nothing is signed.
        [$refused, $refusedDoc] = $this->requestSignature($employee, $this->member);
        $refRow = collect($this->as($this->member)->getJson('/api/workforce/signature/me')->json('signatures'))->firstWhere('request_id', $refused->id);
        $refToken = basename($this->as($this->member)->postJson("/api/workforce/signature/me/{$refRow['id']}/open")->assertOk()->json('url'));
        $this->postJson("/api/public/signature/{$refToken}/decline", ['reason' => 'Clause contestee'])->assertOk();
        $this->assertSame(SignatureRequest::STATUS_DECLINED, $refused->fresh()->status);
        $this->assertNull($refused->fresh()->signed_document_path);
        $this->assertNotSame('signed', $refusedDoc->fresh()->signature_status);
        $this->postJson("/api/public/signature/{$refToken}/sign")->assertStatus(422);

        // Expiry : the scheduled command expires the overdue request, revokes its link, once.
        [$late] = $this->requestSignature($employee, $this->member);
        $lateRow = collect($this->as($this->member)->getJson('/api/workforce/signature/me')->json('signatures'))->firstWhere('request_id', $late->id);
        $lateToken = basename($this->as($this->member)->postJson("/api/workforce/signature/me/{$lateRow['id']}/open")->assertOk()->json('url'));
        SignatureRequest::withoutGlobalScopes()->whereKey($late->id)->update(['expires_at' => now()->subHour()]);
        $this->artisan('workforce:signature-expire')->assertSuccessful();
        $this->artisan('workforce:signature-expire')->assertSuccessful();
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $late->fresh()->status);
        $this->postJson("/api/public/signature/{$lateToken}/sign")->assertStatus(422);
        $states = collect($this->as($this->member)->getJson('/api/workforce/signature/me')->json('signatures'))->pluck('state', 'request_id');
        $this->assertSame(['signed', 'declined', 'expired'], [$states[$req->id], $states[$refused->id], $states[$late->id]]);
        // The completed request is not touched by the expiry run.
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $req->fresh()->status);
    }

    public function test_external_provider_is_honest_about_what_is_not_proven(): void
    {
        $secret = 'whsec_lot4_test_only';
        config(['signature.external.webhook_secret' => $secret, 'signature.external.replay_tolerance_seconds' => 300,
            'signature.external.api_base' => null, 'signature.external.api_key' => null]);
        $employee = $this->employee('SIGX', $this->member->id);
        $doc = $this->generatedDocument($employee->id);

        // Without provider credentials the adapter runs in SANDBOX : the request can be created…
        $res = $this->as($this->owner)->postJson('/api/workforce/signature', [
            'generated_document_id' => $doc->id, 'provider' => 'external',
            'signers' => [['role' => 'employee', 'name' => 'Marie', 'email' => $this->member->email, 'order' => 1, 'party_type' => 'employee', 'party_id' => $employee->id]],
        ])->assertCreated();
        $req = SignatureRequest::withoutGlobalScopes()->with('signers')->findOrFail($res->json('id'));
        $signer = $req->signers->first();

        // …the signer sees it, with NO in-app action (the ceremony belongs to the provider).
        $row = collect($this->as($this->member)->getJson('/api/workforce/signature/me')->assertOk()->json('signatures'))->firstWhere('request_id', $req->id);
        $this->assertSame([true, false], [$row['handled_by_provider'], $row['can_sign']]);
        $this->as($this->member)->postJson("/api/workforce/signature/me/{$row['id']}/open")->assertStatus(422);

        $post = function (array $payload, ?string $signature = null, ?int $ts = null) use ($secret) {
            $raw = json_encode($payload);
            $ts ??= now()->getTimestamp();

            return $this->call('POST', '/api/webhooks/signatures/external', [], [], [], [
                'HTTP_X-Signature-Timestamp' => (string) $ts,
                'HTTP_X-Signature' => $signature ?? hash_hmac('sha256', $ts.'.'.$raw, $secret),
                'CONTENT_TYPE' => 'application/json',
            ], $raw);
        };
        $event = ['id' => 'evt-lot4-1', 'type' => 'signer.signed', 'provider_reference' => $req->provider_reference, 'signer_reference' => $signer->evidence_reference];

        // Webhook authenticity : forged signature and stale timestamp are refused and change nothing.
        $this->assertGreaterThanOrEqual(400, $post($event, 'deadbeef')->getStatusCode());
        $this->assertGreaterThanOrEqual(400, $post($event, null, now()->subHours(2)->getTimestamp())->getStatusCode());
        $this->assertSame(SignatureSigner::STATUS_INVITED, $signer->fresh()->status);

        // A genuine event is applied once (replay = no second effect)…
        $post($event)->assertOk();
        $post($event)->assertOk();
        $this->assertSame(1, \App\Modules\Workforce\Signature\Models\SignatureWebhookEvent::withoutGlobalScopes()->where('event_id', 'evt-lot4-1')->count());

        // …but the SANDBOX can never produce a « completed » request : no sealed document, no evidence.
        $post(['id' => 'evt-lot4-2', 'type' => 'envelope.completed', 'provider_reference' => $req->provider_reference])->assertOk();
        $req->refresh();
        $this->assertNotSame(SignatureRequest::STATUS_COMPLETED, $req->status, 'no completion without a document sealed by a real provider');
        $this->assertNull($req->signed_document_path);
        $this->assertNotSame('signed', $doc->fresh()->signature_status);
    }
}
