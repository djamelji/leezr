<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentIntegrityService;
use App\Core\Documents\Services\DocumentRequestService;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-810.1 — integrity of document requests. A request may be « submitted », « in review » or « approved »
 * only when its subject holds the piece (a document row with a referenced file) ; a failed storage write
 * stops the upload before anything is recorded ; deleting a piece resets its request atomically. The three
 * interfaces (À traiter, person's file, My space) read the same request and the same document.
 */
class Adr8101DocumentRequestIntegrityTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $c;

    private User $owner;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        [$this->c, $this->owner, $this->alice, $this->bob] = $this->tenant('A');
    }

    private function tenant(string $tag): array
    {
        $c = Company::create(['name' => "D8101 $tag", 'slug' => 'd8101-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        $admin = $this->setUpCompanyRbac($c);
        foreach (['id_card' => 0, 'driving_license' => 10] as $code => $order) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => DocumentType::where('code', $code)->value('id'), 'enabled' => true, 'required_override' => false, 'order' => $order]);
        }
        $dispatcher = CompanyRole::create(['company_id' => $c->id, 'key' => 'dispatcher', 'name' => 'Dispatcher', 'is_administrative' => false,
            'doc_config' => [['code' => 'id_card', 'order' => 0, 'visible' => true], ['code' => 'driving_license', 'order' => 10, 'visible' => false]]]);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        $mk = function (string $name) use ($c, $dispatcher) {
            $u = User::factory()->create(['first_name' => $name]);
            $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $dispatcher->id]);
            Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'user_id' => $u->id, 'first_name' => $name, 'last_name' => $c->name, 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid()]);

            return $u;
        };

        return [$c, $owner, $mk('Alice'), $mk('Bob')];
    }

    private function as(User $u, ?Company $c = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->c)->id]);
    }

    private function ref(User $u): string
    {
        return 'employee-'.Employee::withoutGlobalScopes()->where('user_id', $u->id)->value('id');
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent("dl-{$marker}.pdf", "%PDF-1.4\nADR8101[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function type(): DocumentType
    {
        return DocumentType::where('code', 'driving_license')->firstOrFail();
    }

    private function request(User $u): ?DocumentRequest
    {
        return DocumentRequest::where('company_id', $this->c->id)->where('user_id', $u->id)->where('document_type_id', $this->type()->id)->first();
    }

    /** The three projections of the same piece : queue row, person's file row, My space row. */
    private function views(User $person): array
    {
        $queue = collect($this->as($this->owner)->getJson('/api/company/document-requests/queue')->assertOk()->json('queue'))
            ->first(fn ($r) => ($r['user']['id'] ?? null) === $person->id && ($r['document_type']['code'] ?? null) === 'driving_license');
        $file = collect($this->as($this->owner)->getJson("/api/company/people/{$this->ref($person)}/documents")->assertOk()->json('documents'))->firstWhere('code', 'driving_license');
        $mine = collect($this->as($person)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', 'driving_license');

        return [$queue, $file, $mine];
    }

    private function assertSameState(string $state, ?string $requestStatus, array $file, array $mine): void
    {
        $this->assertSame($state, $file['compliance_status'], 'person file');
        $this->assertSame($state, $mine['compliance_status'], 'my space');
        $this->assertSame($requestStatus, $file['request_status']);
        $this->assertSame($requestStatus, $mine['request_status']);
    }

    public function test_file_backed_states_cannot_be_written_without_a_file(): void
    {
        $type = $this->type();
        $base = ['company_id' => $this->c->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->alice->id, 'user_id' => $this->alice->id, 'document_type_id' => $type->id, 'requested_at' => now()];

        foreach (DocumentRequest::FILE_BACKED_STATES as $status) {
            try {
                DocumentRequest::create($base + ['status' => $status]);
                $this->fail("{$status} written without a file");
            } catch (ValidationException) {
            }
        }

        // The exact shape that produced DEV request 5 (direct updateOrCreate to « submitted ») is refused.
        $this->expectExceptionCompat(fn () => DocumentRequest::updateOrCreate(
            ['company_id' => $this->c->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->alice->id, 'document_type_id' => $type->id],
            ['user_id' => $this->alice->id, 'status' => 'submitted', 'requested_at' => now(), 'submitted_at' => now()],
        ));
        // The engine refuses too.
        $this->expectExceptionCompat(fn () => app(DocumentRequestService::class)->markSubmitted($this->c->id, DocumentSubject::user($this->alice->id), $type, $this->owner));
        $this->assertSame(0, DocumentRequest::where('company_id', $this->c->id)->count());
        $this->assertSame(0, DocumentRequestEvent::where('company_id', $this->c->id)->count());

        // States that do not claim a file stay writable.
        $req = DocumentRequest::create($base + ['status' => DocumentRequest::STATUS_REQUESTED]);
        $req->update(['status' => DocumentRequest::STATUS_CANCELLED]);
        $this->assertSame('cancelled', $req->fresh()->status);

        // A document row without a file reference is not evidence.
        MemberDocument::create(['company_id' => $this->c->id, 'user_id' => $this->alice->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->alice->id, 'document_type_id' => $type->id, 'file_path' => '', 'file_name' => 'x.pdf', 'file_size_bytes' => 1, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->alice->id]);
        $this->expectExceptionCompat(fn () => $req->update(['status' => DocumentRequest::STATUS_REQUESTED]) && $req->update(['status' => DocumentRequest::STATUS_SUBMITTED]));
        $this->assertSame('requested', $req->fresh()->status);
    }

    public function test_storage_failure_records_nothing(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->alice), 'document_type_code' => 'driving_license'])->assertSuccessful();

        // The integrity guard itself : a failed write (false) or a missing file stops the upload.
        $integrity = app(DocumentIntegrityService::class);
        $this->expectExceptionCompat(fn () => $integrity->storedHash(false));
        $this->expectExceptionCompat(fn () => $integrity->storedHash('documents/none.pdf'));

        // Every write to the private disk fails (full disk, permissions…).
        $root = storage_path('framework/testing/adr8101-'.uniqid());
        $adapter = new class($root) extends LocalFilesystemAdapter
        {
            public function write(string $path, string $contents, Config $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'disk full');
            }

            public function writeStream(string $path, $contents, Config $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'disk full');
            }
        };
        Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false]));

        // My space (the person) and the person's file (the company) : both refused, nothing recorded.
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('S1')]], ['Accept' => 'application/json'])->assertStatus(422);
        $this->as($this->owner)->post("/api/company/people/{$this->ref($this->alice)}/documents/driving_license", ['files' => [$this->pdf('S2')]], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame('requested', $this->request($this->alice)->status);
        $this->assertSame(0, MemberDocument::where('company_id', $this->c->id)->count());
        $this->assertSame(0, DocumentRequestEvent::where('document_request_id', $this->request($this->alice)->id)->where('event', 'submitted')->count());
    }

    public function test_lifecycle_same_identity_in_the_three_interfaces(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->alice), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $requestId = $this->request($this->alice)->id;

        // Demandé.
        [$queue, $file, $mine] = $this->views($this->alice);
        $this->assertSame([$requestId, 'requested'], [$queue['id'], $queue['status']]);
        $this->assertSameState('requested', 'requested', $file, $mine);

        // Transmis.
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('V1')]], ['Accept' => 'application/json'])->assertSuccessful();
        [$queue, $file, $mine] = $this->views($this->alice);
        $this->assertSame([$requestId, 'submitted'], [$queue['id'], $queue['status']]);
        $this->assertSameState('pending_validation', 'submitted', $file, $mine);
        $docId = $file['upload']['id'];
        $this->assertSame($docId, $mine['upload']['id']);
        $this->assertSame($file['upload']['file_name'], $queue['upload']['file_name']);

        // Refusé : still listed in the queue (awaiting the person), reason visible to her.
        $this->as($this->owner)->putJson("/api/company/people/{$this->ref($this->alice)}/documents/driving_license/review", ['status' => 'rejected', 'review_note' => 'Illisible'])->assertOk();
        [$queue, $file, $mine] = $this->views($this->alice);
        $this->assertSame([$requestId, 'rejected'], [$queue['id'], $queue['status']]);
        $this->assertSameState('rejected', 'rejected', $file, $mine);
        $this->assertSame('Illisible', $mine['request_review_note']);

        // Remplacé : a new version, same document identity, the previous version kept.
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('V2')]], ['Accept' => 'application/json'])->assertSuccessful();
        [$queue, $file, $mine] = $this->views($this->alice);
        $this->assertSame('submitted', $queue['status']);
        $this->assertSame($docId, $file['upload']['id']);
        $this->assertSame($docId, $mine['upload']['id']);
        $this->assertCount(1, $this->as($this->alice)->getJson('/api/profile/documents/driving_license/versions')->assertOk()->json('versions'));

        // Approuvé : leaves the queue ; same document in the file and in My space.
        $this->as($this->owner)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$requestId], 'action' => 'approved'])->assertOk()->assertJsonPath('processed', 1);
        [$queue, $file, $mine] = $this->views($this->alice);
        $this->assertNull($queue);
        $this->assertSameState('valid', 'approved', $file, $mine);
        $this->assertSame($docId, $file['upload']['id']);
        $this->assertSame($docId, $mine['upload']['id']);
        $this->assertStringContainsString('ADR8101[V2]', $this->as($this->alice)->get('/api/profile/documents/driving_license/download')->assertOk()->streamedContent());

        // Expiré : both projections agree.
        MemberDocument::withoutGlobalScopes()->whereKey($docId)->update(['expires_at' => now()->subDay()]);
        [, $file, $mine] = $this->views($this->alice);
        $this->assertSameState('expired', 'approved', $file, $mine);

        // In review : still awaiting validation (never « valid » while being checked).
        MemberDocument::withoutGlobalScopes()->whereKey($docId)->update(['expires_at' => now()->addYear()]);
        $req = DocumentRequest::findOrFail($requestId);
        $req->update(['status' => DocumentRequest::STATUS_TO_REPLACE]);
        $req->update(['status' => DocumentRequest::STATUS_SUBMITTED]);
        app(DocumentRequestService::class)->startReview($req->fresh(), $this->owner);
        [$queue, $file, $mine] = $this->views($this->alice);
        $this->assertSame('in_review', $queue['status']);
        $this->assertSameState('pending_validation', 'in_review', $file, $mine);
    }

    public function test_deleting_the_piece_resets_its_request_atomically(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->alice), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('D1')]], ['Accept' => 'application/json'])->assertSuccessful();
        app(DocumentRequestService::class)->startReview($this->request($this->alice), $this->owner);
        $path = MemberDocument::withoutGlobalScopes()->where('user_id', $this->alice->id)->value('file_path');
        Storage::disk('local')->assertExists($path);

        $membershipId = $this->c->memberships()->where('user_id', $this->alice->id)->value('id');
        $this->as($this->owner)->deleteJson("/api/company/members/{$membershipId}/documents/driving_license")->assertSuccessful();

        $this->assertSame('requested', $this->request($this->alice)->status, 'an « in review » request without its piece goes back to « requested »');
        Storage::disk('local')->assertMissing($path);
        [, $file, $mine] = $this->views($this->alice);
        $this->assertSameState('requested', 'requested', $file, $mine);
    }

    public function test_negative_http_controls(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->bob), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $this->as($this->bob)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('B1')]], ['Accept' => 'application/json'])->assertSuccessful();
        $bobRequest = $this->request($this->bob);
        $bobRef = $this->ref($this->bob);

        // A colleague : no queue, no review, no bulk action, no upload or download on Bob's file.
        $this->as($this->alice)->getJson('/api/company/document-requests/queue')->assertForbidden();
        $this->as($this->alice)->putJson("/api/company/people/{$bobRef}/documents/driving_license/review", ['status' => 'approved'])->assertForbidden();
        $this->as($this->alice)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$bobRequest->id], 'action' => 'approved'])->assertForbidden();
        $this->as($this->alice)->post("/api/company/people/{$bobRef}/documents/driving_license", ['files' => [$this->pdf('X')]], ['Accept' => 'application/json'])->assertForbidden();
        $this->as($this->alice)->get("/api/company/people/{$bobRef}/documents/driving_license/download")->assertForbidden();
        // The person cannot approve her own piece.
        $this->as($this->bob)->putJson("/api/company/people/{$bobRef}/documents/driving_license/review", ['status' => 'approved'])->assertForbidden();

        // Another company : the request cannot be reached or approved.
        [$b, $ownerB] = $this->tenant('B');
        $this->as($ownerB, $b)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$bobRequest->id], 'action' => 'approved'])->assertOk()->assertJsonPath('processed', 0);
        $this->as($ownerB, $b)->putJson("/api/company/document-requests/{$bobRequest->id}/cancel")->assertNotFound();
        $this->as($ownerB, $b)->putJson("/api/company/people/{$bobRef}/documents/driving_license/review", ['status' => 'approved'])->assertNotFound();

        $this->assertSame('submitted', $bobRequest->fresh()->status);
    }

    private function expectExceptionCompat(callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected a ValidationException.');
    }
}
