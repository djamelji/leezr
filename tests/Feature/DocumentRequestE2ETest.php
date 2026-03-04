<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
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
 * ADR-194: E2E validation — Document Request lifecycle.
 *
 * Full cycle: activate doc type → request → submission → approval → re-request.
 */
class DocumentRequestE2ETest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $member;
    private Company $company;
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

        $this->company = Company::create([
            'name' => 'E2E DocReq Co',
            'slug' => 'e2e-docreq-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);

        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $driverRole = CompanyRole::create([
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
            'company_role_id' => $driverRole->id,
        ]);

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

    // ─── Full lifecycle: request → verify → approve → re-request ──

    public function test_full_document_request_lifecycle(): void
    {
        // Step 1: Request a document
        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $response->assertCreated()
            ->assertJsonPath('document_request.status', 'requested');

        // Step 2: Verify DB state
        $this->assertDatabaseHas('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => 'requested',
        ]);

        // Step 3: Queue should show the pending request
        $queueResponse = $this->actAsOwner()
            ->getJson('/api/company/document-requests/queue');

        $queueResponse->assertOk();

        $queue = $queueResponse->json('queue');

        $this->assertGreaterThanOrEqual(1, count($queue));

        $found = collect($queue)->first(fn ($item) => $item['user']['id'] === $this->member->id);

        $this->assertNotNull($found, 'Member request appears in queue');
        $this->assertEquals('requested', $found['status']);

        // Step 4: Duplicate request should be rejected
        $dupResponse = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $dupResponse->assertStatus(422);

        // Step 5: Approve the request (simulate by direct DB update)
        DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $this->docType->id)
            ->update([
                'status' => DocumentRequest::STATUS_APPROVED,
                'reviewed_at' => now(),
            ]);

        // Step 6: Queue should no longer show this request
        $queueAfter = $this->actAsOwner()
            ->getJson('/api/company/document-requests/queue');

        $queueItems = $queueAfter->json('queue');
        $stillFound = collect($queueItems)->first(fn ($item) => $item['user']['id'] === $this->member->id && $item['document_type']['code'] === 'id_card');

        $this->assertNull($stillFound, 'Approved request no longer in queue');

        // Step 7: Re-request after approval should work (reuses same row)
        $reRequest = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'id_card',
            ]);

        $reRequest->assertCreated()
            ->assertJsonPath('document_request.status', 'requested');

        // Step 8: Only 1 row exists (UNIQUE constraint respected)
        $rowCount = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $this->docType->id)
            ->count();

        $this->assertEquals(1, $rowCount, 'Single row reused (UNIQUE constraint)');

        // Step 9: Status is reset
        $this->assertDatabaseHas('document_requests', [
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $this->docType->id,
            'status' => 'requested',
            'reviewed_at' => null,
        ]);
    }

    // ─── Request fails when activation is disabled ───────────

    public function test_request_fails_when_activation_disabled(): void
    {
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

    // ─── Request fails for unknown document type ─────────────

    public function test_request_fails_for_unknown_document_type(): void
    {
        $response = $this->actAsOwner()
            ->postJson('/api/company/document-requests', [
                'user_id' => $this->member->id,
                'document_type_code' => 'nonexistent_doc',
            ]);

        $response->assertStatus(404);
    }
}
