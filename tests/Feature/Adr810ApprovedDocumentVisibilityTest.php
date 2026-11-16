<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
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
 * ADR-810 — a document requested to a person, submitted then approved, stays visible in HER space and in her
 * file even when her role's document configuration hides that type by default. The role configuration only
 * shapes the DEFAULT expected list ; it never hides a document that concerns the person explicitly (request,
 * file). Access control is unchanged : listing is never the security control.
 */
class Adr810ApprovedDocumentVisibilityTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $c;

    private User $owner;

    private User $alice;

    private User $bob;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        [$this->c, $this->owner, $this->alice, $this->bob, $this->hr] = $this->tenant('A');
    }

    private function tenant(string $tag): array
    {
        $c = Company::create(['name' => "D810 $tag", 'slug' => 'd810-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($c);
        $admin = $this->setUpCompanyRbac($c);
        foreach (['id_card' => 0, 'driving_license' => 10, 'medical_certificate' => 20] as $code => $order) {
            DocumentTypeActivation::create(['company_id' => $c->id, 'document_type_id' => DocumentType::where('code', $code)->value('id'), 'enabled' => true, 'required_override' => false, 'order' => $order]);
        }
        // The role hides driving_license and medical_certificate from its DEFAULT list (as on DEV « dispatcher »).
        $dispatcher = CompanyRole::create(['company_id' => $c->id, 'key' => 'dispatcher', 'name' => 'Dispatcher', 'is_administrative' => false,
            'doc_config' => [['code' => 'id_card', 'order' => 0, 'visible' => true], ['code' => 'driving_license', 'order' => 10, 'visible' => false], ['code' => 'medical_certificate', 'order' => 20, 'visible' => false]]]);
        $hrRole = CompanyRole::create(['company_id' => $c->id, 'key' => 'hr', 'name' => 'RH', 'is_administrative' => true]);
        $hrRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['documents.view', 'documents.manage', 'members.view'])->pluck('id')->all());
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        $mk = function (string $name, CompanyRole $role) use ($c) {
            $u = User::factory()->create(['first_name' => $name]);
            $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);
            Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'user_id' => $u->id, 'first_name' => $name, 'last_name' => $c->name, 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid()]);

            return $u;
        };

        return [$c, $owner, $mk('Alice', $dispatcher), $mk('Bob', $dispatcher), $mk('Rh', $hrRole)];
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
        return UploadedFile::fake()->createWithContent("dl-{$marker}.pdf", "%PDF-1.4\nADR810[{$marker}]\n".str_repeat('x', 64)."\n%%EOF");
    }

    private function mine(User $u, string $code): ?array
    {
        return collect($this->as($u)->getJson('/api/profile/documents')->assertOk()->json('documents'))->firstWhere('code', $code);
    }

    private function inFile(User $viewer, User $person, string $code): ?array
    {
        return collect($this->as($viewer)->getJson("/api/company/people/{$this->ref($person)}/documents")->assertOk()->json('documents'))->firstWhere('code', $code);
    }

    public function test_requested_submitted_approved_document_stays_visible_everywhere(): void
    {
        // Hidden by the role, never requested : invisible (the role configuration still applies).
        $this->assertNull($this->mine($this->alice, 'driving_license'));
        $this->assertNull($this->inFile($this->owner, $this->alice, 'driving_license'));

        // Requested, not provided : visible to Alice (she can provide it) and in her file.
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->alice), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $this->assertSame('requested', $this->mine($this->alice, 'driving_license')['request_status']);
        $this->assertSame('requested', $this->inFile($this->owner, $this->alice, 'driving_license')['request_status']);

        // Provided by Alice : submitted, file present on both sides.
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('A1')]], ['Accept' => 'application/json'])->assertSuccessful();
        $doc = MemberDocument::where('company_id', $this->c->id)->where('user_id', $this->alice->id)->where('document_type_id', DocumentType::where('code', 'driving_license')->value('id'))->sole();
        $this->assertSame('submitted', $this->inFile($this->owner, $this->alice, 'driving_license')['request_status']);

        // Approved in « À traiter » (bulk path of the screen) : the real result is reported…
        $req = DocumentRequest::where('company_id', $this->c->id)->where('user_id', $this->alice->id)->where('document_type_id', $doc->document_type_id)->sole();
        $this->as($this->owner)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$req->id], 'action' => 'approved'])->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('skipped', 0);
        $this->assertSame('approved', $req->fresh()->status);
        // … and the SAME document identity is found in her file and her space.
        $file = $this->inFile($this->owner, $this->alice, 'driving_license');
        $this->assertSame('approved', $file['request_status']);
        $this->assertSame($doc->id, $file['upload']['id']);
        $this->assertSame($doc->id, $this->mine($this->alice, 'driving_license')['upload']['id']);
        $this->assertSame($doc->id, $this->inFile($this->hr, $this->alice, 'driving_license')['upload']['id'], 'HR with documents.view');
        $this->assertStringContainsString('ADR810[A1]', $this->as($this->alice)->get('/api/profile/documents/driving_license/download')->assertOk()->streamedContent());
        $this->assertStringContainsString('ADR810[A1]', $this->as($this->owner)->get("/api/company/people/{$this->ref($this->alice)}/documents/driving_license/download")->assertOk()->streamedContent());

        // Still hidden : the type never requested to her.
        $this->assertNull($this->mine($this->alice, 'medical_certificate'));
        // Bob (same role, nothing requested) does not see driving_license.
        $this->assertNull($this->mine($this->bob, 'driving_license'));
    }

    public function test_statuses_rejected_replaced_expired_cancelled(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->alice), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('R1')]], ['Accept' => 'application/json'])->assertSuccessful();
        $ref = $this->ref($this->alice);

        // Rejected : visible with its reason, re-providable.
        $this->as($this->owner)->putJson("/api/company/people/{$ref}/documents/driving_license/review", ['status' => 'rejected', 'review_note' => 'Illisible'])->assertOk();
        $mine = $this->mine($this->alice, 'driving_license');
        $this->assertSame('rejected', $mine['request_status']);
        $this->assertSame('Illisible', $mine['request_review_note']);

        // Replaced : new file = same identity, new version ; the latest is shown, the old version stays downloadable by HR.
        $this->as($this->alice)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('R2')]], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame('submitted', $this->mine($this->alice, 'driving_license')['request_status']);
        $this->assertStringContainsString('ADR810[R2]', $this->as($this->alice)->get('/api/profile/documents/driving_license/download')->assertOk()->streamedContent());
        $versions = $this->as($this->owner)->getJson("/api/company/people/{$ref}/documents/driving_license/versions")->assertOk()->json('versions');
        $this->assertGreaterThanOrEqual(1, count($versions));
        $this->as($this->owner)->putJson("/api/company/people/{$ref}/documents/driving_license/review", ['status' => 'approved'])->assertOk();

        // Expired : still visible, lifecycle says so.
        MemberDocument::where('user_id', $this->alice->id)->update(['expires_at' => now()->subDay()]);
        $this->assertSame('expired', $this->mine($this->alice, 'driving_license')['lifecycle_status']);

        // Cancelled request without file : the role's default (hidden) applies again ; with a file, the file keeps it visible.
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->bob), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $bobReq = DocumentRequest::where('company_id', $this->c->id)->where('user_id', $this->bob->id)->where('status', 'requested')->sole();
        $this->assertNotNull($this->mine($this->bob, 'driving_license'));
        $this->as($this->owner)->putJson("/api/company/document-requests/{$bobReq->id}/cancel")->assertSuccessful();
        $this->assertNull($this->mine($this->bob, 'driving_license'));
    }

    public function test_approval_without_file_is_never_reported_as_success(): void
    {
        // A submitted request whose file is gone (DEV request 5 case) : approval refused, request unchanged.
        $type = DocumentType::where('code', 'driving_license')->firstOrFail();
        // ADR-810.1 : this state can no longer be written through the model ; a pre-existing row is simulated in SQL.
        $id = \Illuminate\Support\Facades\DB::table('document_requests')->insertGetId(['company_id' => $this->c->id, 'document_subject_type' => 'user', 'document_subject_id' => $this->alice->id, 'user_id' => $this->alice->id,
            'document_type_id' => $type->id, 'status' => DocumentRequest::STATUS_SUBMITTED, 'requested_at' => now(), 'submitted_at' => now(), 'requires_validation' => true, 'created_at' => now(), 'updated_at' => now()]);
        $req = DocumentRequest::findOrFail($id);
        $this->as($this->owner)->postJson('/api/company/document-requests/bulk-action', ['ids' => [$req->id], 'action' => 'approved'])->assertOk()
            ->assertJsonPath('processed', 0)->assertJsonPath('skipped', 1);
        $this->as($this->owner)->putJson("/api/company/people/{$this->ref($this->alice)}/documents/driving_license/review", ['status' => 'approved'])->assertStatus(422);
        $this->assertSame('submitted', $req->fresh()->status);
        // The person still sees the request (and can provide the file again).
        $this->assertSame('submitted', $this->mine($this->alice, 'driving_license')['request_status']);
        $this->assertNull($this->mine($this->alice, 'driving_license')['upload']);
    }

    public function test_security_colleague_tenant_accountless_and_permissions(): void
    {
        $this->as($this->owner)->postJson('/api/company/document-requests', ['person_ref' => $this->ref($this->bob), 'document_type_code' => 'driving_license'])->assertSuccessful();
        $this->as($this->bob)->post('/api/profile/documents/driving_license', ['files' => [$this->pdf('B1')]], ['Accept' => 'application/json'])->assertSuccessful();
        $bobRef = $this->ref($this->bob);

        // Alice : no access to Bob's file, list, download or versions ; her own self route never serves Bob's file.
        $this->as($this->alice)->getJson("/api/company/people/{$bobRef}/documents")->assertForbidden();
        $this->as($this->alice)->get("/api/company/people/{$bobRef}/documents/driving_license/download")->assertForbidden();
        $this->as($this->alice)->getJson("/api/company/people/{$bobRef}/documents/driving_license/versions")->assertForbidden();
        $this->as($this->alice)->get('/api/profile/documents/driving_license/download')->assertNotFound();

        // Tenant isolation : the same people endpoints from another company.
        [$b, $ownerB] = $this->tenant('B');
        $this->as($ownerB, $b)->getJson("/api/company/people/{$bobRef}/documents")->assertNotFound();
        $this->as($ownerB, $b)->get("/api/company/people/{$bobRef}/documents/driving_license/download")->assertNotFound();

        // Account-less employee : documents attached to the HR record, visible in the file.
        $acc = Employee::withoutGlobalScopes()->create(['company_id' => $this->c->id, 'user_id' => null, 'first_name' => 'Sans', 'last_name' => 'Compte', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid()]);
        $this->as($this->owner)->post("/api/company/people/employee-{$acc->id}/documents/id_card", ['files' => [$this->pdf('S1')]], ['Accept' => 'application/json'])->assertSuccessful();
        $row = collect($this->as($this->owner)->getJson("/api/company/people/employee-{$acc->id}/documents")->assertOk()->json('documents'))->firstWhere('code', 'id_card');
        $this->assertNotNull($row['upload']);
        $this->as($this->alice)->getJson("/api/company/people/employee-{$acc->id}/documents")->assertForbidden();
    }
}
