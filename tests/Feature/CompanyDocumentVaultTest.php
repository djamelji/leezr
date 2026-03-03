<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Storage\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-174: Company Document Vault tests.
 *
 * Covers: index (scope, market, upload status, activation),
 * upload (create, replace, quota, mime, scope guard),
 * download, StorageQuotaService, resolver backward compat.
 */
class CompanyDocumentVaultTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Vault Co',
            'slug' => 'vault-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->company);
        $this->setUpCompanyRbac($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
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

    private function actAsOwner()
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 1. Index: returns only scope=company types
    // ═══════════════════════════════════════════════════════

    public function test_index_returns_only_company_scope_types(): void
    {
        $response = $this->actAsOwner()->getJson('/api/company/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // Company scope types should be present (FR market)
        $this->assertContains('kbis', $codes);
        $this->assertContains('insurance_certificate', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 2. Index: excludes company_user scope types
    // ═══════════════════════════════════════════════════════

    public function test_index_excludes_company_user_scope_types(): void
    {
        $response = $this->actAsOwner()->getJson('/api/company/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        $this->assertNotContains('id_card', $codes);
        $this->assertNotContains('driving_license', $codes);
        $this->assertNotContains('medical_certificate', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 3. Index: respects market filtering
    // ═══════════════════════════════════════════════════════

    public function test_index_respects_market_filtering(): void
    {
        // Create a GB company
        $gbCompany = Company::create([
            'name' => 'GB Co',
            'slug' => 'gb-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'GB',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($gbCompany);
        $this->setUpCompanyRbac($gbCompany);

        $gbUser = User::factory()->create();

        $gbCompany->memberships()->create([
            'user_id' => $gbUser->id,
            'role' => 'owner',
        ]);

        // Activate all doc types for GB company
        $docTypes = DocumentType::where('is_system', true)->get();
        foreach ($docTypes as $index => $docType) {
            DocumentTypeActivation::create([
                'company_id' => $gbCompany->id,
                'document_type_id' => $docType->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }

        $response = $this->actingAs($gbUser)
            ->withHeaders(['X-Company-Id' => $gbCompany->id])
            ->getJson('/api/company/documents');

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // kbis has applicable_markets=['FR'] → excluded for GB
        $this->assertNotContains('kbis', $codes);
        // transport_license also FR-only → excluded
        $this->assertNotContains('transport_license', $codes);
        // insurance_certificate has no market restriction → present
        $this->assertContains('insurance_certificate', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // 4. Index: includes upload status
    // ═══════════════════════════════════════════════════════

    public function test_index_includes_upload_status(): void
    {
        // Upload a kbis document
        $file = UploadedFile::fake()->create('kbis.pdf', 100, 'application/pdf');

        $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file])
            ->assertOk();

        // Check index
        $response = $this->actAsOwner()->getJson('/api/company/documents');

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $kbis = $docs->firstWhere('code', 'kbis');

        $this->assertNotNull($kbis);
        $this->assertNotNull($kbis['upload']);
        $this->assertEquals('kbis.pdf', $kbis['upload']['file_name']);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Index: empty when no activations
    // ═══════════════════════════════════════════════════════

    public function test_index_empty_when_no_activations(): void
    {
        DocumentTypeActivation::where('company_id', $this->company->id)->delete();

        $response = $this->actAsOwner()->getJson('/api/company/documents');

        $response->assertOk();
        $this->assertEmpty($response->json('documents'));
    }

    // ═══════════════════════════════════════════════════════
    // 6. Upload: creates CompanyDocument
    // ═══════════════════════════════════════════════════════

    public function test_upload_creates_company_document(): void
    {
        $file = UploadedFile::fake()->create('kbis.pdf', 100, 'application/pdf');

        $response = $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('document.code', 'kbis');
        $response->assertJsonPath('document.file_name', 'kbis.pdf');

        $this->assertDatabaseHas('company_documents', [
            'company_id' => $this->company->id,
            'file_name' => 'kbis.pdf',
            'uploaded_by' => $this->owner->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 7. Upload: replaces and cleans old file
    // ═══════════════════════════════════════════════════════

    public function test_upload_replaces_and_cleans_old_file(): void
    {
        // First upload
        $file1 = UploadedFile::fake()->create('old_kbis.pdf', 200, 'application/pdf');

        $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file1])
            ->assertOk();

        $oldDoc = CompanyDocument::where('company_id', $this->company->id)->first();
        $oldPath = $oldDoc->file_path;

        Storage::disk('local')->assertExists($oldPath);

        // Second upload (replacement)
        $file2 = UploadedFile::fake()->create('new_kbis.pdf', 150, 'application/pdf');

        $response = $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file2]);

        $response->assertOk();
        $response->assertJsonPath('document.file_name', 'new_kbis.pdf');

        Storage::disk('local')->assertMissing($oldPath);

        $this->assertEquals(1, CompanyDocument::where('company_id', $this->company->id)->count());
    }

    // ═══════════════════════════════════════════════════════
    // 8. Upload: rejects company_user scope type
    // ═══════════════════════════════════════════════════════

    public function test_upload_rejects_company_user_scope_type(): void
    {
        $file = UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');

        $response = $this->actAsOwner()
            ->postJson('/api/company/documents/id_card', ['file' => $file]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 9. Upload: rejects invalid mime type
    // ═══════════════════════════════════════════════════════

    public function test_upload_rejects_invalid_mime(): void
    {
        $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

        $response = $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 10. Download: works for uploaded document
    // ═══════════════════════════════════════════════════════

    public function test_download_company_document(): void
    {
        $file = UploadedFile::fake()->create('kbis.pdf', 100, 'application/pdf');

        $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file])
            ->assertOk();

        $response = $this->actAsOwner()
            ->get('/api/company/documents/kbis/download');

        $response->assertOk();
        $response->assertDownload('kbis.pdf');
    }

    // ═══════════════════════════════════════════════════════
    // 11. Download: returns 404 when no document uploaded
    // ═══════════════════════════════════════════════════════

    public function test_download_returns_404_when_no_document(): void
    {
        $response = $this->actAsOwner()
            ->get('/api/company/documents/kbis/download');

        $response->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════
    // 12. Quota: allows smaller replacement
    // ═══════════════════════════════════════════════════════

    public function test_quota_allows_smaller_replacement(): void
    {
        $this->company->update(['plan_key' => 'starter']);

        $type = DocumentType::where('code', 'kbis')->first();

        CompanyDocument::create([
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/old_kbis.pdf',
            'file_name' => 'old_kbis.pdf',
            'file_size_bytes' => 1_073_741_800, // ~1GB - small margin
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        $file = UploadedFile::fake()->create('small.pdf', 100, 'application/pdf');

        $response = $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file]);

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════
    // 13. Quota: blocks when projected exceeds
    // ═══════════════════════════════════════════════════════

    public function test_quota_blocks_when_projected_exceeds(): void
    {
        $this->company->update(['plan_key' => 'starter']);

        // Fill quota with a member document
        $type = DocumentType::where('code', 'driving_license')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/fake/big.pdf',
            'file_name' => 'big.pdf',
            'file_size_bytes' => 1_073_741_824, // Exactly 1 GB
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        // Try uploading a company document → projected exceeds
        $file = UploadedFile::fake()->create('kbis.pdf', 100, 'application/pdf');

        $response = $this->actAsOwner()
            ->postJson('/api/company/documents/kbis', ['file' => $file]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 14. StorageQuotaService: usage sums both tables
    // ═══════════════════════════════════════════════════════

    public function test_storage_quota_sums_both_tables(): void
    {
        $memberType = DocumentType::where('code', 'id_card')->first();
        $companyType = DocumentType::where('code', 'kbis')->first();

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'document_type_id' => $memberType->id,
            'file_path' => 'documents/fake/id.pdf',
            'file_name' => 'id.pdf',
            'file_size_bytes' => 500_000,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        CompanyDocument::create([
            'company_id' => $this->company->id,
            'document_type_id' => $companyType->id,
            'file_path' => 'documents/fake/kbis.pdf',
            'file_name' => 'kbis.pdf',
            'file_size_bytes' => 300_000,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        $usage = StorageQuotaService::usage($this->company);

        $this->assertEquals(800_000, $usage['used_bytes']);
    }

    // ═══════════════════════════════════════════════════════
    // 15. Resolver: scope=null backward compat
    // ═══════════════════════════════════════════════════════

    public function test_resolver_scope_null_returns_all_scopes(): void
    {
        $documents = DocumentResolverService::resolve(
            $this->owner,
            $this->company->id,
            marketKey: $this->company->market_key,
        );

        $scopes = array_unique(array_column($documents, 'scope'));

        $this->assertContains(DocumentType::SCOPE_COMPANY, $scopes);
        $this->assertContains(DocumentType::SCOPE_COMPANY_USER, $scopes);
    }
}
