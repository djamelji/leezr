<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\PersonDocumentContinuity;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-770 (M7 LOT 4) — coherence of a PERSON's documents across the subject transition
 * `employee:{id}` → `user:{id}` (account linked to an HR record) and back (access removed).
 *
 * Everything goes through the real HTTP endpoints (admin file, review, compliance, campaigns,
 * self-service) ; the scheduled lifecycle goes through its real command. The expected rule is
 * BUSINESS coherence — one current document, one request, one compliance slot, one history per
 * person and type — not a de-duplication at display time.
 */
class M7Lot4DocumentSubjectCoherenceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private CompanyRole $adminRole;
    private CompanyRole $plainRole;
    private User $owner;
    private DocumentType $type;

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

        [$this->company, $this->adminRole, $this->owner] = $this->makeCompany('Coherence Co');
        $this->plainRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'plain', 'name' => 'Plain', 'is_administrative' => false]);
        $this->type = DocumentType::where('code', 'id_card')->firstOrFail();
    }

    /** @return array{0: Company, 1: CompanyRole, 2: User} */
    private function makeCompany(string $name): array
    {
        $company = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => 'workforce'], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        $adminRole = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
        // A single REQUIRED personal document type keeps the compliance figures exact and readable.
        $type = DocumentType::where('code', 'id_card')->firstOrFail();
        DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $type->id, 'enabled' => true, 'required_override' => true, 'order' => 10]);

        return [$company, $adminRole, $owner];
    }

    private function as(User $u, ?Company $company = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($company ?? $this->company)->id]);
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent("id-{$marker}.pdf", "%PDF-1.4\nL4[{$marker}]\n".str_repeat('x', 48)."\n%%EOF");
    }

    private function accountless(?Company $company = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => ($company ?? $this->company)->id, 'user_id' => null,
            'first_name' => 'No', 'last_name' => 'Account', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function member(?Company $company = null): User
    {
        $user = User::factory()->create();
        $role = $company ? CompanyRole::where('company_id', $company->id)->where('is_administrative', false)->first() : $this->plainRole;
        ($company ?? $this->company)->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role?->id]);

        return $user;
    }

    /** The link as most paths write it (access grant, import…) : no document service is called. */
    private function link(Employee $employee, User $user): void
    {
        $employee->refresh()->forceFill(['user_id' => $user->id])->save();
    }

    private function adminUpload(string $ref, string $marker, ?string $expires = null)
    {
        return $this->as($this->owner)->postJson("/api/company/people/{$ref}/documents/id_card", [
            'file' => $this->pdf($marker), 'expires_at' => $expires ?? now()->addYear()->toDateString(),
        ]);
    }

    private function fileEntry(User $actor, string $ref): array
    {
        return collect($this->as($actor)->getJson("/api/company/people/{$ref}/documents")->assertOk()->json('documents'))->firstWhere('code', 'id_card');
    }

    private function compliance(): array
    {
        return $this->as($this->owner)->getJson('/api/company/documents/compliance')->assertOk()->json('summary');
    }

    private function docsOf(Employee $e, ?User $u): \Illuminate\Support\Collection
    {
        return MemberDocument::where('company_id', $this->company->id)->where('document_type_id', $this->type->id)
            ->where(function ($q) use ($e, $u) {
                $q->where(fn ($w) => $w->where('document_subject_type', 'employee')->where('document_subject_id', $e->id));
                if ($u) {
                    $q->orWhere('user_id', $u->id)->orWhere(fn ($w) => $w->where('document_subject_type', 'user')->where('document_subject_id', $u->id));
                }
            })->get();
    }

    private function requestsOf(Employee $e, ?User $u): \Illuminate\Support\Collection
    {
        return DocumentRequest::where('company_id', $this->company->id)->where('document_type_id', $this->type->id)
            ->where(function ($q) use ($e, $u) {
                $q->where(fn ($w) => $w->where('document_subject_type', 'employee')->where('document_subject_id', $e->id));
                if ($u) {
                    $q->orWhere('user_id', $u->id)->orWhere(fn ($w) => $w->where('document_subject_type', 'user')->where('document_subject_id', $u->id));
                }
            })->get();
    }

    // ═══ Main path : accountless → documents + request → account linked ═════

    public function test_linking_keeps_one_document_one_request_and_exact_compliance_for_admin_and_self(): void
    {
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'V1')->assertOk();
        $this->adminUpload("employee-{$e->id}", 'V2')->assertOk();

        $before = $this->compliance();
        $this->assertSame([2, 1], [$before['total'], $before['pending_validation']], 'owner + accountless employee ; the deposit awaits validation');

        $u = $this->member();
        $this->link($e, $u);
        $mid = Membership::where('company_id', $this->company->id)->where('user_id', $u->id)->value('id');

        // ── Right after the link, BEFORE the person ever opens her space ──
        $after = $this->compliance();
        $this->assertSame([2, 1, 0], [$after['total'], $after['pending_validation'], $after['requested']], 'same person, same slot : still awaiting validation, never « to provide »');

        $entry = $this->fileEntry($this->owner, "employee-{$e->id}");
        $this->assertTrue($entry['has_file']);
        $this->assertSame('submitted', $entry['request_status'], 'the request filed on the HR record IS the request of the person');
        $this->assertTrue($entry['can_review']);

        $this->as($this->owner)->get("/api/company/people/employee-{$e->id}/documents/id_card/download")->assertOk();
        $this->as($this->owner)->get("/api/company/people/user-{$u->id}/documents/id_card/download")->assertOk();
        $this->assertCount(1, $this->as($this->owner)->getJson("/api/company/people/employee-{$e->id}/documents/id_card/versions")->assertOk()->json('versions'));

        // ── The administrator validates the piece that was filed before the account existed ──
        $this->as($this->owner)->putJson("/api/company/members/{$mid}/documents/id_card/review", ['status' => 'approved'])->assertOk();
        $valid = $this->compliance();
        $this->assertSame([2, 1, 0, 0], [$valid['total'], $valid['valid'], $valid['pending_validation'], $valid['requested']]);

        // ── Business coherence in the database : ONE document, ONE request, ONE history ──
        $docs = $this->docsOf($e, $u);
        $requests = $this->requestsOf($e, $u);
        $this->assertCount(1, $docs);
        $this->assertCount(1, $requests);
        $this->assertSame(['user', $u->id, $u->id], [$docs[0]->document_subject_type, $docs[0]->document_subject_id, $docs[0]->user_id]);
        $this->assertSame(['user', $u->id, 'approved'], [$requests[0]->document_subject_type, $requests[0]->document_subject_id, $requests[0]->status]);
        $events = DocumentRequestEvent::where('document_request_id', $requests[0]->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['submitted', 'submitted', 'subject_moved', 'approved'], $events, 'the history started on the HR record continues on the same request');
        $this->assertSame(1, DocumentVersion::where('documentable_id', $docs[0]->id)->where('documentable_type', $docs[0]->getMorphClass())->count());

        // ── Self-service sees the same truth ──
        $mine = collect($this->as($u)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertNotNull($mine['upload']);
        $this->assertSame('approved', $mine['request_status']);
        $this->as($u)->get('/api/profile/documents/id_card/download')->assertOk();
        $this->assertCount(1, $this->as($u)->getJson('/api/profile/documents/id_card/versions')->assertOk()->json('versions'));
    }

    public function test_person_replaces_a_validated_piece_filed_before_her_account(): void
    {
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'V1')->assertOk();
        $u = $this->member();
        $this->link($e, $u);
        $mid = Membership::where('company_id', $this->company->id)->where('user_id', $u->id)->value('id');
        $this->as($this->owner)->putJson("/api/company/members/{$mid}/documents/id_card/review", ['status' => 'approved'])->assertOk();

        // Renewal by the person herself : back to validation, previous file kept as a version.
        $this->as($u)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('V2'), 'expires_at' => now()->addYears(2)->toDateString()])->assertSuccessful();

        $this->assertCount(1, $this->docsOf($e, $u));
        $requests = $this->requestsOf($e, $u);
        $this->assertCount(1, $requests);
        $this->assertSame('submitted', $requests[0]->status);
        $this->assertSame(1, $this->compliance()['pending_validation']);
        $this->assertCount(1, $this->as($this->owner)->getJson("/api/company/people/employee-{$e->id}/documents/id_card/versions")->json('versions'));
    }

    // ═══ Conflicts : both subjects hold a row of the same type ══════════════

    public function test_placeholder_request_of_the_account_never_hides_the_real_request_of_the_hr_record(): void
    {
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'V1')->assertOk();
        $u = $this->member();
        $this->link($e, $u);

        // Legacy state : a « to provide » placeholder was lazily created for the account (ADR-389)
        // while the real, submitted request still sat on the HR record.
        $placeholder = DocumentRequest::create([
            'company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => $u->id, 'user_id' => $u->id,
            'document_type_id' => $this->type->id, 'status' => 'requested', 'requested_at' => now(), 'message' => 'Merci de fournir votre pièce', 'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $real = DocumentRequest::where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->firstOrFail();

        $entry = $this->fileEntry($this->owner, "employee-{$e->id}");
        $this->assertSame(['submitted', true, true], [$entry['request_status'], $entry['has_file'], $entry['can_review']]);
        $this->assertSame(1, $this->compliance()['pending_validation']);
        $this->assertSame(0, $this->compliance()['requested']);

        $requests = $this->requestsOf($e, $u);
        $this->assertCount(1, $requests, 'one request per person and type');
        $this->assertSame($real->id, $requests[0]->id, 'the advanced request survives, the pristine placeholder is folded into it');
        $this->assertNull(DocumentRequest::find($placeholder->id));
        $this->assertSame('Merci de fournir votre pièce', $requests[0]->message, 'what the administrator wrote on the placeholder is kept');
        $merged = DocumentRequestEvent::where('document_request_id', $real->id)->where('event', 'merged')->firstOrFail();
        $this->assertSame($placeholder->id, $merged->metadata['merged_request']['id']);
        $this->assertTrue(CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'document.subject_transition')->exists());
    }

    public function test_when_both_subjects_hold_a_document_the_accounts_one_is_current_and_the_other_becomes_history(): void
    {
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'OLD1')->assertOk();
        $this->adminUpload("employee-{$e->id}", 'OLD2')->assertOk(); // the HR-record document has its own version
        $old = MemberDocument::where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->firstOrFail();
        $oldPaths = array_merge([$old->file_path], DocumentVersion::where('documentable_id', $old->id)->pluck('file_path')->all());
        $oldRequest = DocumentRequest::where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->firstOrFail();

        // Legacy state : after the link, a second document of the same type was created for the account.
        $u = $this->member();
        $this->link($e, $u);
        Storage::disk('local')->put($newPath = "documents/{$this->company->id}/{$u->id}/new.pdf", '%PDF new');
        $new = MemberDocument::create([
            'company_id' => $this->company->id, 'user_id' => $u->id, 'document_subject_type' => 'user', 'document_subject_id' => $u->id,
            'document_type_id' => $this->type->id, 'file_path' => $newPath, 'file_name' => 'new.pdf', 'file_size_bytes' => 8, 'mime_type' => 'application/pdf',
            'file_sha256' => hash('sha256', '%PDF new'), 'published_state' => 'published', 'uploaded_by' => $u->id, 'expires_at' => now()->addYears(3),
        ]);
        $newRequest = DocumentRequest::create([
            'company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => $u->id, 'user_id' => $u->id,
            'document_type_id' => $this->type->id, 'status' => 'submitted', 'requested_at' => now(), 'submitted_at' => now(),
        ]);

        $entry = $this->fileEntry($this->owner, "employee-{$e->id}");
        $this->assertSame('new.pdf', $entry['upload']['file_name'], 'the document of the account is the current one');

        // One current document ; the HR-record document and its versions are now its HISTORY.
        $docs = $this->docsOf($e, $u);
        $this->assertCount(1, $docs);
        $this->assertSame($new->id, $docs[0]->id);
        $this->assertNull(MemberDocument::find($old->id));
        $versions = DocumentVersion::where('documentable_id', $new->id)->where('documentable_type', $new->getMorphClass())->orderBy('version_no')->get();
        $this->assertEqualsCanonicalizing($oldPaths, $versions->pluck('file_path')->all(), 'every earlier file is a version of the current document');
        $this->assertSame([1, 2], $versions->pluck('version_no')->all());
        foreach (array_merge($oldPaths, [$newPath]) as $path) {
            Storage::disk('local')->assertExists($path); // nothing copied, nothing deleted
        }
        foreach ($this->as($this->owner)->getJson("/api/company/people/employee-{$e->id}/documents/id_card/versions")->assertOk()->json('versions') as $v) {
            $this->as($this->owner)->get("/api/company/people/employee-{$e->id}/documents/id_card/versions/{$v['id']}/download")->assertOk();
        }

        // One request : the account's ; the other one is folded into it with its events.
        $requests = $this->requestsOf($e, $u);
        $this->assertCount(1, $requests);
        $this->assertSame($newRequest->id, $requests[0]->id);
        $this->assertNull(DocumentRequest::find($oldRequest->id));
        $this->assertSame(2, DocumentRequestEvent::where('document_request_id', $newRequest->id)->where('event', 'submitted')->count(), 'the events of the folded request are kept');
        $this->assertSame(1, $this->compliance()['pending_validation']);
    }

    // ═══ Scheduled lifecycle ════════════════════════════════════════════════

    public function test_renewal_and_reminders_address_the_person_once_after_linking(): void
    {
        CompanyDocumentSetting::updateOrCreate(['company_id' => $this->company->id], ['auto_renew_enabled' => true, 'renew_days_before' => 30, 'auto_remind_enabled' => true, 'remind_after_days' => 7]);
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'V1', now()->addDays(10)->toDateString())->assertOk();
        // The deposit was validated-equivalent (no workflow without account) : close its request cycle.
        DocumentRequest::where('document_subject_type', 'employee')->where('document_subject_id', $e->id)->update(['status' => 'approved']);
        $u = $this->member();
        $this->link($e, $u);

        $this->artisan('documents:auto-renew')->assertSuccessful();
        $this->artisan('documents:auto-renew')->assertSuccessful();

        $requests = $this->requestsOf($e, $u);
        $this->assertCount(1, $requests, 'one renewal request for the person');
        $this->assertSame(['user', $u->id, $u->id, 'requested'], [$requests[0]->document_subject_type, $requests[0]->document_subject_id, $requests[0]->user_id, $requests[0]->status]);
        $this->assertSame(1, DocumentRequestEvent::where('document_request_id', $requests[0]->id)->where('event', 'renewed')->count());
        $this->assertCount(1, $this->docsOf($e, $u));

        // The person — who now has an account — is the one told, in her own space.
        $mine = collect($this->as($u)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertSame('requested', $mine['request_status']);
        $this->assertNotNull($mine['upload']);
    }

    // ═══ Campaigns ══════════════════════════════════════════════════════════

    public function test_campaign_launched_before_the_link_still_tracks_the_same_person_and_a_new_one_does_not_duplicate(): void
    {
        $e = $this->accountless();
        $first = $this->as($this->owner)->postJson('/api/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'employees', 'employee_ids' => [$e->id]]])->assertCreated()->json('campaign.id');
        $recipient = DocumentCampaignRecipient::where('document_campaign_id', $first)->firstOrFail();
        $this->assertSame(['employee', $e->id], [$recipient->document_subject_type, $recipient->document_subject_id]);
        $requestId = $recipient->document_request_id;
        $this->assertNotNull($requestId);

        $u = $this->member();
        $this->link($e, $u);
        $this->as($u)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('MINE'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();

        // The first campaign follows the person : her deposit is RECEIVED, she is no longer « without account ».
        $summary = $this->as($this->owner)->getJson("/api/company/document-campaigns/{$first}")->assertOk()->json();
        $this->assertSame([1, 0], [$summary['partition']['received'], $summary['partition']['pending']]);
        $this->assertSame(0, $summary['dimensions']['account_less']);
        $recipient->refresh();
        $this->assertSame(['user', $u->id, $requestId], [$recipient->document_subject_type, $recipient->document_subject_id, $recipient->document_request_id]);
        $this->assertSame('submitted', DocumentRequest::findOrFail($requestId)->status);

        // A second campaign on the same person and type never opens a second active request.
        $second = $this->as($this->owner)->postJson('/api/company/document-campaigns', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'employees', 'employee_ids' => [$e->id]]])->assertCreated()->json('campaign.id');
        $this->assertSame(1, DocumentCampaignRecipient::where('document_campaign_id', $second)->count());
        $this->assertCount(1, $this->requestsOf($e, $u));
        $this->assertCount(1, $this->docsOf($e, $u));
    }

    // ═══ Idempotence and tenant isolation ═══════════════════════════════════

    public function test_transition_is_idempotent_and_never_touches_another_company(): void
    {
        [$other, , $otherOwner] = $this->makeCompany('Other Co');
        $foreign = $this->accountless($other);
        $this->as($otherOwner, $other)->postJson("/api/company/people/employee-{$foreign->id}/documents/id_card", ['file' => $this->pdf('F'), 'expires_at' => now()->addYear()->toDateString()])->assertOk();

        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'V1')->assertOk();
        $u = $this->member();
        $this->link($e, $u);
        // The same account is ALSO linked to the accountless record of the other company's… no : it is
        // not even a member there. Its documents there must stay exactly where they are.
        $snapshot = fn () => [
            MemberDocument::where('company_id', $other->id)->get(['id', 'user_id', 'document_subject_type', 'document_subject_id'])->toArray(),
            DocumentRequest::where('company_id', $other->id)->get(['id', 'user_id', 'document_subject_type', 'document_subject_id', 'status'])->toArray(),
        ];
        $foreignBefore = $snapshot();

        $first = PersonDocumentContinuity::adopt($this->company, $u);
        $this->assertGreaterThan(0, $first);
        $state = fn () => [$this->docsOf($e, $u)->toArray(), $this->requestsOf($e, $u)->toArray(), DocumentRequestEvent::count(), DocumentVersion::count()];
        $after = $state();
        $audits = CompanyAuditLog::where('action', 'document.subject_transition')->count();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, PersonDocumentContinuity::adopt($this->company, $u));
            $this->assertSame(0, PersonDocumentContinuity::reconcile($this->company));
            $this->compliance();
            $this->fileEntry($this->owner, "employee-{$e->id}");
        }
        $this->assertSame($after, $state(), 'repeating the transition changes nothing');
        $this->assertSame($audits, CompanyAuditLog::where('action', 'document.subject_transition')->count());
        $this->assertSame($foreignBefore, $snapshot(), 'the other company is untouched');
        $this->assertSame(0, PersonDocumentContinuity::reconcile($other));
        $this->assertSame($foreignBefore, $snapshot());
    }

    // ═══ Access removed, record linked to ANOTHER account ════════════════════

    public function test_documents_return_to_the_hr_record_when_access_is_removed_and_never_leak_to_another_account(): void
    {
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'BEFORE')->assertOk();
        $a = $this->member();
        $this->link($e, $a);
        // After the link, the first account deposits a newer file herself.
        $this->as($a)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('BY-A'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();
        $doc = $this->docsOf($e, $a)->sole();
        $this->assertSame($a->id, $doc->user_id);

        // The administrator removes the access of A (the real endpoint : the HR record is unlinked).
        $mid = Membership::where('company_id', $this->company->id)->where('user_id', $a->id)->value('id');
        $this->as($this->owner)->deleteJson("/api/company/members/{$mid}")->assertOk();
        $this->assertNull($e->fresh()->user_id);

        // The documents of the person stay on HER HR record : nothing vanished for the company.
        $entry = $this->fileEntry($this->owner, "employee-{$e->id}");
        $this->assertTrue($entry['has_file']);
        $this->as($this->owner)->get("/api/company/people/employee-{$e->id}/documents/id_card/download")->assertOk();
        $this->assertSame(1, $this->compliance()['pending_validation']);
        $doc->refresh();
        $this->assertSame(['employee', $e->id, null], [$doc->document_subject_type, $doc->document_subject_id, $doc->user_id]);
        $this->assertCount(1, $this->as($this->owner)->getJson("/api/company/people/employee-{$e->id}/documents/id_card/versions")->json('versions'));

        // The former member sees nothing any more.
        $this->as($a)->getJson('/api/profile/documents')->assertStatus(403);

        // The record is then linked to ANOTHER account : what A held is not handed over to B.
        $b = $this->member();
        $this->link($e, $b);
        $mineB = collect($this->as($b)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertNull($mineB['upload'] ?? null, 'B does not inherit the files held by A');
        $this->as($b)->get('/api/profile/documents/id_card/download')->assertStatus(404);
        $doc->refresh();
        $this->assertSame(['employee', $e->id, null], [$doc->document_subject_type, $doc->document_subject_id, $doc->user_id], 'still on the HR record, not on B');

        // The administrator keeps the piece on the person's file ; B is asked for her own.
        $this->assertTrue($this->fileEntry($this->owner, "employee-{$e->id}")['has_file']);

        // Even on the administrative read model, B looking at HER OWN file does not see what A held…
        $own = collect(\App\Core\Documents\ReadModels\MemberDocumentWorkflowReadModel::get($b, $this->company, null, $b, $e->id)['documents'])->firstWhere('code', 'id_card');
        $this->assertFalse($own['has_file']);
        // …and the scheduled renewal never chases B for a piece that A deposited.
        CompanyDocumentSetting::updateOrCreate(['company_id' => $this->company->id], ['auto_renew_enabled' => true, 'renew_days_before' => 400, 'auto_remind_enabled' => false, 'remind_after_days' => 7]);
        DocumentRequest::where('company_id', $this->company->id)->update(['status' => 'approved']);
        $requestsBefore = DocumentRequest::where('company_id', $this->company->id)->count();
        $this->artisan('documents:auto-renew')->assertSuccessful();
        $this->assertSame($requestsBefore, DocumentRequest::where('company_id', $this->company->id)->count());
        $this->assertSame(0, DocumentRequest::where('company_id', $this->company->id)->where('status', 'requested')->count());

        // B deposits her own : it becomes the current document, the earlier one is history (not hers to read alone).
        $this->as($b)->postJson('/api/profile/documents/id_card', ['file' => $this->pdf('BY-B'), 'expires_at' => now()->addYear()->toDateString()])->assertSuccessful();
        $this->as($b)->get('/api/profile/documents/id_card/download')->assertOk();
        $this->assertSame(1, MemberDocument::where('company_id', $this->company->id)->where('user_id', $b->id)->count());
    }

    public function test_relinking_the_same_account_gives_her_documents_back(): void
    {
        $e = $this->accountless();
        $this->adminUpload("employee-{$e->id}", 'V1')->assertOk();
        $a = $this->member();
        $this->link($e, $a);
        $this->assertNotNull(collect($this->as($a)->getJson('/api/profile/documents')->json('documents'))->firstWhere('code', 'id_card')['upload']);

        $mid = Membership::where('company_id', $this->company->id)->where('user_id', $a->id)->value('id');
        $this->as($this->owner)->deleteJson("/api/company/members/{$mid}")->assertOk();
        $this->assertTrue($this->fileEntry($this->owner, "employee-{$e->id}")['has_file']);

        // Same person, same account, access given again.
        $this->company->memberships()->create(['user_id' => $a->id, 'role' => 'user', 'company_role_id' => $this->plainRole->id]);
        $this->link($e, $a);
        $mine = collect($this->as($a)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertNotNull($mine['upload']);
        $this->assertCount(1, $this->docsOf($e, $a));
        $this->assertCount(1, $this->requestsOf($e, $a));
    }
}
