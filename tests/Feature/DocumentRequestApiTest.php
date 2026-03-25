<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-192: Document Request API tests (single + batch + queue).
 */
class DocumentRequestApiTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $member;
    private User $member2;
    private Company $company;
    private CompanyRole $driverRole;
    private DocumentType $docType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->member2 = User::factory()->create();

        $this->company = Company::create([
            'name' => 'DocReq Co',
            'slug' => 'docreq-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);

        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'company_role_id' => $adminRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->member2->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        // Use a system doc type (seeded by DocumentTypeCatalog)
        $this->docType = DocumentType::where('code', 'id_card')
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->first();

        DocumentTypeActivation::create([
            'company_id' => $this->company->id,
            'document_type_id' => $this->docType->id,
            'enabled' => true,
            'order' => 0,
        ]);
    }

    private function actAsOwner()
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── Single Request ────────────────────────────────

    public function test_request_single_ok(): void
    {
        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Document requested.')
            ->assertJsonPath('document_request.status', 'requested');

        $this->assertDatabaseHas('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => 'requested',
        ]);
    }

    public function test_request_single_rejects_if_activation_disabled(): void
    {
        // Disable the activation
        DocumentTypeActivation::where('company_id', $this->company->id)
            ->where('document_type_id', $this->docType->id)
            ->update(['enabled' => false]);

        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $response->assertStatus(422);
    }

    public function test_request_single_rejects_if_active_duplicate(): void
    {
        // Create an existing active request
        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => DocumentRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $response->assertStatus(422);
    }

    public function test_request_single_allows_after_previous_closed(): void
    {
        // A previously approved request should not block a new one
        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => DocumentRequest::STATUS_APPROVED,
            'requested_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $response->assertCreated();
    }

    // ─── Batch Request ─────────────────────────────────

    public function test_batch_creates_for_eligible_members(): void
    {
        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests/batch', [
                'scope' => 'role',
                'company_role_ids' => [$this->driverRole->id],
                'document_type_code' => 'id_card',
            ]);

        $response->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertEquals(2, DocumentRequest::where('company_id', $this->company->id)
            ->where('document_type_id', $this->docType->id)
            ->where('status', 'requested')
            ->count());
    }

    public function test_batch_skips_members_with_active_request(): void
    {
        // member already has active request
        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'requested_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests/batch', [
                'scope' => 'role',
                'company_role_ids' => [$this->driverRole->id],
                'document_type_code' => 'id_card',
            ]);

        $response->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1);
    }

    public function test_batch_audit_is_single_entry(): void
    {
        $countBefore = CompanyAuditLog::where('company_id', $this->company->id)
            ->where('action', 'document.batch_requested')
            ->count();

        $this->actAsOwner()
            ->postJson('/api/company/document-requests/batch', [
                'scope' => 'role',
                'company_role_ids' => [$this->driverRole->id],
                'document_type_code' => 'id_card',
            ]);

        $countAfter = CompanyAuditLog::where('company_id', $this->company->id)
            ->where('action', 'document.batch_requested')
            ->count();

        $this->assertEquals(1, $countAfter - $countBefore);
    }

    public function test_batch_rejects_if_activation_disabled(): void
    {
        DocumentTypeActivation::where('company_id', $this->company->id)
            ->where('document_type_id', $this->docType->id)
            ->update(['enabled' => false]);

        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests/batch', [
                'scope' => 'role',
                'company_role_ids' => [$this->driverRole->id],
                'document_type_code' => 'id_card',
            ]);

        $response->assertStatus(422);
    }

    // ─── Queue ─────────────────────────────────────────

    public function test_queue_returns_active_requests_sorted(): void
    {
        // Create requests at different times
        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => DocumentRequest::STATUS_REQUESTED,
            'requested_at' => now()->subHour(),
        ]);

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member2->id,
            'document_type_id' => $this->docType->id,
            'status' => DocumentRequest::STATUS_SUBMITTED,
            'requested_at' => now(),
        ]);

        // Closed request should NOT appear
        $anotherDocType = DocumentType::where('code', 'kbis')->first()
            ?? DocumentType::create([
                'code' => 'queue_test_doc',
                'label' => 'Queue Test',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'is_system' => true,
            ]);

        DocumentRequest::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $anotherDocType->id,
            'status' => DocumentRequest::STATUS_APPROVED,
            'requested_at' => now()->subDay(),
            'reviewed_at' => now(),
        ]);

        $response = $this->actAsOwner()
            ->getJson('/api/company/document-requests/queue');

        $response->assertOk()
            ->assertJsonCount(2, 'queue');

        $queue = $response->json('queue');

        // Most recent first
        $this->assertEquals($this->member2->id, $queue[0]['user']['id']);
        $this->assertEquals($this->member->id, $queue[1]['user']['id']);

        // Structure check
        $this->assertArrayHasKey('status', $queue[0]);
        $this->assertArrayHasKey('user', $queue[0]);
        $this->assertArrayHasKey('document_type', $queue[0]);
        $this->assertArrayHasKey('requested_at', $queue[0]);
    }
}
