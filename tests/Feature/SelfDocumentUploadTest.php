<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Billing\CompanyEntitlements;
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
 * ADR-173: Self-document upload Phase 1 tests.
 *
 * Covers: index (scope, market, doc_config, mandatory), upload (create, replace,
 * quota, mime, scope guard), download (own, other user).
 */
class SelfDocumentUploadTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $user;
    private Company $company;
    private CompanyRole $role;
    private $membership;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Self Doc Co',
            'slug' => 'self-doc-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
            'doc_config' => null,
        ]);

        $this->membership = $this->company->memberships()->create([
            'user_id' => $this->user->id,
            'role' => 'user',
            'company_role_id' => $this->role->id,
        ]);

        // Activate all document types for this company
        $docTypes = DocumentType::where('is_system', true)->get();
        foreach ($docTypes as $index => $docType) {
            DocumentTypeActivation::create([
                'company_id' => $this->company->id,
                'document_type_id' => $docType->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }
    }

    private function actAsSelf()
    {
        return $this->actingAs($this->user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 1. Index: returns activated company_user types only
    // ═══════════════════════════════════════════════════════

    public function test_self_index_returns_activated_company_user_types(): void
    {
        $response = $this->actAsSelf()->getJson('/api/profile/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // company_user types should be present
        $this->assertContains('id_card', $codes);
        $this->assertContains('driving_license', $codes);
        $this->assertContains('medical_certificate', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 2. Index: excludes company scope types
    // ═══════════════════════════════════════════════════════

    public function test_self_index_excludes_company_scope_types(): void
    {
        $response = $this->actAsSelf()->getJson('/api/profile/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // Company scope types should NOT appear in self view
        $this->assertNotContains('kbis', $codes);
        $this->assertNotContains('insurance_certificate', $codes);
        $this->assertNotContains('transport_license', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 3. Index: respects market filtering
    // ═══════════════════════════════════════════════════════

    public function test_self_index_respects_market_filtering(): void
    {
        // Create a non-FR company (GB is seeded by MarketRegistry)
        $otherCompany = Company::create([
            'name' => 'Non FR Co',
            'slug' => 'non-fr-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'GB',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($otherCompany);
        $this->setUpCompanyRbac($otherCompany);

        $otherUser = User::factory()->create();

        $otherCompany->memberships()->create([
            'user_id' => $otherUser->id,
            'role' => 'owner',
        ]);

        // Activate all doc types for other company
        $docTypes = DocumentType::where('is_system', true)->get();
        foreach ($docTypes as $index => $docType) {
            DocumentTypeActivation::create([
                'company_id' => $otherCompany->id,
                'document_type_id' => $docType->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }

        $response = $this->actingAs($otherUser)
            ->withHeaders(['X-Company-Id' => $otherCompany->id])
            ->getJson('/api/profile/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // id_card has applicable_markets=['FR'] → should be excluded for GB company
        $this->assertNotContains('id_card', $codes);
        // driving_license has no market restriction → should be present
        $this->assertContains('driving_license', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 4. Index: respects doc_config visible=false
    // ═══════════════════════════════════════════════════════

    public function test_self_index_respects_doc_config_visibility(): void
    {
        // Update role to hide medical_certificate (non-mandatory for this config)
        $this->role->update([
            'required_tags' => null,
            'doc_config' => [
                ['code' => 'medical_certificate', 'visible' => false],
                ['code' => 'driving_license', 'visible' => false],
            ],
        ]);

        $response = $this->actAsSelf()->getJson('/api/profile/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // medical_certificate is non-mandatory (no tags) → should be hidden
        $this->assertNotContains('medical_certificate', $codes);
        // id_card should still be visible (not in doc_config)
        $this->assertContains('id_card', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Index: mandatory documents always visible
    // ═══════════════════════════════════════════════════════

    public function test_self_index_mandatory_always_visible(): void
    {
        // driving_license is mandatory via tag DRIVING + required_tags=[DRIVING]
        // doc_config tries to hide it → mandatory guard should prevent
        $this->role->update([
            'required_tags' => [TagDictionary::DRIVING],
            'doc_config' => [
                ['code' => 'driving_license', 'visible' => false],
            ],
        ]);

        $response = $this->actAsSelf()->getJson('/api/profile/documents');

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $dl = $docs->firstWhere('code', 'driving_license');

        $this->assertNotNull($dl, 'driving_license should remain visible (mandatory guard)');
        $this->assertTrue($dl['required'], 'driving_license should be required (mandatory)');
    }

    // ═══════════════════════════════════════════════════════
    // 6. Index: empty when no activations
    // ═══════════════════════════════════════════════════════

    public function test_self_index_empty_when_no_activations(): void
    {
        // Remove all activations
        DocumentTypeActivation::where('company_id', $this->company->id)->delete();

        $response = $this->actAsSelf()->getJson('/api/profile/documents');

        $response->assertOk();
        $this->assertEmpty($response->json('documents'));
    }

    // ═══════════════════════════════════════════════════════
    // 7. Upload: creates a MemberDocument
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_creates_document(): void
    {
        $file = UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');

        $response = $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('document.code', 'id_card');
        $response->assertJsonPath('document.file_name', 'id.pdf');

        $this->assertDatabaseHas('member_documents', [
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'file_name' => 'id.pdf',
            'uploaded_by' => $this->user->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 8. Upload: replaces and cleans old file
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_replaces_and_cleans_old_file(): void
    {
        // First upload
        $file1 = UploadedFile::fake()->create('old.pdf', 200, 'application/pdf');

        $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file1])
            ->assertOk();

        $oldDoc = MemberDocument::where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)
            ->first();
        $oldPath = $oldDoc->file_path;

        Storage::disk('local')->assertExists($oldPath);

        // Second upload (replacement)
        $file2 = UploadedFile::fake()->create('new.pdf', 150, 'application/pdf');

        $response = $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file2]);

        $response->assertOk();
        $response->assertJsonPath('document.file_name', 'new.pdf');

        // Old file should be deleted from disk
        Storage::disk('local')->assertMissing($oldPath);

        // Only one record should exist (upsert)
        $this->assertEquals(1, MemberDocument::where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)
            ->count());
    }

    // ═══════════════════════════════════════════════════════
    // 9. Upload: quota delta allows smaller replacement
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_quota_delta_allows_smaller_replacement(): void
    {
        // Use starter plan (1 GB quota)
        $this->company->update(['plan_key' => 'starter']);

        // Seed a large existing document to fill quota
        $type = DocumentType::where('code', 'id_card')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/old.pdf',
            'file_name' => 'old.pdf',
            'file_size_bytes' => 1_073_741_800, // ~1GB - small margin
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->user->id,
        ]);

        // Upload a SMALLER replacement (delta is negative → always allowed)
        $file = UploadedFile::fake()->create('small.pdf', 100, 'application/pdf');

        $response = $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file]);

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════
    // 10. Upload: quota blocks when projected exceeds
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_quota_blocks_when_projected_exceeds(): void
    {
        // Use starter plan (1 GB quota)
        $this->company->update(['plan_key' => 'starter']);

        // Seed documents to fill the quota
        $type = DocumentType::where('code', 'driving_license')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/big.pdf',
            'file_name' => 'big.pdf',
            'file_size_bytes' => 1_073_741_824, // Exactly 1 GB
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->user->id,
        ]);

        // Try uploading a NEW document (different type) → projected = 1GB + newSize > 1GB
        $file = UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');

        $response = $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 11. Upload: rejects invalid mime type
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_rejects_invalid_mime(): void
    {
        $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

        $response = $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 12. Upload: rejects company scope type
    // ═══════════════════════════════════════════════════════

    public function test_self_upload_rejects_company_scope_type(): void
    {
        $file = UploadedFile::fake()->create('kbis.pdf', 100, 'application/pdf');

        $response = $this->actAsSelf()
            ->postJson('/api/profile/documents/kbis', ['file' => $file]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 13. Download: own document works
    // ═══════════════════════════════════════════════════════

    public function test_self_download_own_document(): void
    {
        // Upload first
        $file = UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf');

        $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file])
            ->assertOk();

        // Download
        $response = $this->actAsSelf()
            ->get('/api/profile/documents/id_card/download');

        $response->assertOk();
        $response->assertDownload('permit.pdf');
    }

    // ═══════════════════════════════════════════════════════
    // 14. Download: other user's document returns 404
    // ═══════════════════════════════════════════════════════

    public function test_self_download_rejects_other_user_document(): void
    {
        // Upload a document as the main user
        $file = UploadedFile::fake()->create('myid.pdf', 100, 'application/pdf');

        $this->actAsSelf()
            ->postJson('/api/profile/documents/id_card', ['file' => $file])
            ->assertOk();

        // Create a different user with a membership in the same company
        $otherUser = User::factory()->create();

        $this->company->memberships()->create([
            'user_id' => $otherUser->id,
            'role' => 'user',
            'company_role_id' => $this->role->id,
        ]);

        // Other user tries to download the first user's document via self endpoint
        $response = $this->actingAs($otherUser)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->get('/api/profile/documents/id_card/download');

        // Should be 404 because other user has no uploaded id_card
        $response->assertNotFound();
    }
}
