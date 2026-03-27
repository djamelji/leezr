<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\TagDictionary;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-176: Document Request Workflow tests.
 *
 * Covers: lazy-create, self upload transitions, admin upload transition,
 * review approve/reject, transition guards, scope guard, company isolation.
 */
class DocumentRequestWorkflowTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $member;
    private Company $company;
    private CompanyRole $driverRole;
    private $membership;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Workflow Co',
            'slug' => 'workflow-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->company);
        $this->setUpCompanyRbac($this->company);

        $this->driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
            'doc_config' => null,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->membership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        // Activate all document types for this company
        // ADR-389: required_override mirrors jobdomain preset (replaces required_by_jobdomains)
        $presetRequired = ['id_card', 'kbis', 'insurance_certificate'];
        $docTypes = DocumentType::where('is_system', true)->get();
        foreach ($docTypes as $index => $docType) {
            DocumentTypeActivation::create([
                'company_id' => $this->company->id,
                'document_type_id' => $docType->id,
                'enabled' => true,
                'required_override' => in_array($docType->code, $presetRequired),
                'order' => $index * 10,
            ]);
        }
    }

    private function actAsOwner()
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function actAsMember()
    {
        return $this->actingAs($this->member)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 1. Admin index lazy-creates requested for mandatory
    // ═══════════════════════════════════════════════════════

    public function test_admin_index_lazy_creates_requested_for_mandatory(): void
    {
        // id_card is mandatory for logistique jobdomain FR
        $this->assertDatabaseMissing('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
        ]);

        $response = $this->actAsOwner()
            ->getJson("/api/company/members/{$this->membership->id}/documents");

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $idCard = $docs->firstWhere('code', 'id_card');

        $this->assertNotNull($idCard);
        $this->assertEquals('requested', $idCard['request_status']);
        $this->assertNotNull($idCard['requested_at']);
        $this->assertFalse($idCard['has_file']);

        // DB has the request
        $type = DocumentType::where('code', 'id_card')->first();

        $this->assertDatabaseHas('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => 'requested',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 2. Admin index does NOT create request for non-required docs
    // ═══════════════════════════════════════════════════════

    public function test_admin_index_does_not_create_request_for_non_mandatory(): void
    {
        $response = $this->actAsOwner()
            ->getJson("/api/company/members/{$this->membership->id}/documents");

        $response->assertOk();

        $docs = collect($response->json('documents'));

        // ADR-389: required = mandatory (catalog) OR required_override (company preset)
        // Non-required docs should NOT have lazy-created requests
        foreach ($docs as $doc) {
            if (! $doc['required']) {
                $this->assertNull(
                    $doc['request_status'],
                    "Non-required doc {$doc['code']} should NOT have a request created",
                );
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // 3. Self upload creates request as submitted
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_creates_request_as_submitted(): void
    {
        $file = UploadedFile::fake()->create('id_card.pdf', 100, 'application/pdf');

        $this->actAsMember()
            ->postJson('/api/profile/documents/id_card', ['file' => $file])
            ->assertOk();

        $type = DocumentType::where('code', 'id_card')->first();

        $this->assertDatabaseHas('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => 'submitted',
        ]);

        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertNotNull($request->submitted_at);
    }

    // ═══════════════════════════════════════════════════════
    // 4. Self re-upload after rejected repasses to submitted
    // ═══════════════════════════════════════════════════════

    public function test_self_reupload_after_rejected_repasses_to_submitted(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        // Create a rejected request
        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_REJECTED,
            'reviewer_id' => $this->owner->id,
            'review_note' => 'Blurry photo',
            'requested_at' => now()->subDay(),
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subHour(),
        ]);

        $file = UploadedFile::fake()->create('id_card_v2.pdf', 100, 'application/pdf');

        $this->actAsMember()
            ->postJson('/api/profile/documents/id_card', ['file' => $file])
            ->assertOk();

        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertEquals('submitted', $request->status);
        $this->assertNull($request->reviewer_id);
        $this->assertNull($request->review_note);
        $this->assertNull($request->reviewed_at);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Self re-upload after approved repasses to submitted
    // ═══════════════════════════════════════════════════════

    public function test_self_reupload_after_approved_repasses_to_submitted(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        // Create an approved request + file
        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/old.pdf',
            'file_name' => 'old.pdf',
            'file_size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->member->id,
        ]);

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_APPROVED,
            'reviewer_id' => $this->owner->id,
            'requested_at' => now()->subDay(),
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subHour(),
        ]);

        $file = UploadedFile::fake()->create('id_card_new.pdf', 100, 'application/pdf');

        $this->actAsMember()
            ->postJson('/api/profile/documents/id_card', ['file' => $file])
            ->assertOk();

        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertEquals('submitted', $request->status);
    }

    // ═══════════════════════════════════════════════════════
    // 6. Review approved works when file exists and submitted
    // ═══════════════════════════════════════════════════════

    public function test_review_approved_works_when_file_exists_and_submitted(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/id.pdf',
            'file_name' => 'id.pdf',
            'file_size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->member->id,
        ]);

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'requested_at' => now()->subDay(),
            'submitted_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->putJson("/api/company/members/{$this->membership->id}/documents/id_card/review", [
                'status' => 'approved',
            ]);

        $response->assertOk();
        $response->assertJsonPath('review.status', 'approved');

        $this->assertDatabaseHas('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => 'approved',
            'reviewer_id' => $this->owner->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 7. Review approved fails when no file (requested only)
    // ═══════════════════════════════════════════════════════

    public function test_review_approved_fails_when_no_file(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->putJson("/api/company/members/{$this->membership->id}/documents/id_card/review", [
                'status' => 'approved',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 8. Review rejected works from submitted
    // ═══════════════════════════════════════════════════════

    public function test_review_rejected_works_from_submitted(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/id.pdf',
            'file_name' => 'id.pdf',
            'file_size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->member->id,
        ]);

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'requested_at' => now()->subDay(),
            'submitted_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->putJson("/api/company/members/{$this->membership->id}/documents/id_card/review", [
                'status' => 'rejected',
                'review_note' => 'Document is expired',
            ]);

        $response->assertOk();
        // ADR-410: rejection auto-re-requests → final status is 'requested'
        $response->assertJsonPath('review.status', 'requested');
        $response->assertJsonPath('review.review_note', 'Document is expired');
    }

    // ═══════════════════════════════════════════════════════
    // 9. Review stores reviewer_id + review_note + reviewed_at
    // ═══════════════════════════════════════════════════════

    public function test_review_stores_all_metadata(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/id.pdf',
            'file_name' => 'id.pdf',
            'file_size_bytes' => 100,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->member->id,
        ]);

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'requested_at' => now()->subDay(),
            'submitted_at' => now(),
        ]);

        $this->actAsOwner()
            ->putJson("/api/company/members/{$this->membership->id}/documents/id_card/review", [
                'status' => 'rejected',
                'review_note' => 'Photo too dark',
            ])
            ->assertOk();

        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertEquals($this->owner->id, $request->reviewer_id);
        $this->assertEquals('Photo too dark', $request->review_note);
        $this->assertNotNull($request->reviewed_at);
    }

    // ═══════════════════════════════════════════════════════
    // 10. Review rejects scope=company type
    // ═══════════════════════════════════════════════════════

    public function test_review_rejects_company_scope_type(): void
    {
        $type = DocumentType::where('code', 'kbis')->first(); // scope=company

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->putJson("/api/company/members/{$this->membership->id}/documents/kbis/review", [
                'status' => 'approved',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 11. Company isolation: outsider cannot review
    // ═══════════════════════════════════════════════════════

    public function test_company_isolation_outsider_cannot_review(): void
    {
        $outsider = User::factory()->create();

        $type = DocumentType::where('code', 'id_card')->first();

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($outsider)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson("/api/company/members/{$this->membership->id}/documents/id_card/review", [
                'status' => 'approved',
            ]);

        // Outsider should be rejected by middleware or use case
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    // ═══════════════════════════════════════════════════════
    // 12. Admin upload also transitions to submitted
    // ═══════════════════════════════════════════════════════

    public function test_admin_upload_transitions_to_submitted(): void
    {
        $type = DocumentType::where('code', 'id_card')->first();

        // Start with a requested state
        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'status' => DocumentRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('id_card.pdf', 100, 'application/pdf');

        $this->actAsOwner()
            ->postJson("/api/company/members/{$this->membership->id}/documents/id_card", [
                'file' => $file,
            ])
            ->assertOk();

        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertEquals('submitted', $request->status);
        $this->assertNotNull($request->submitted_at);
    }
}
