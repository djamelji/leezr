<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentLifecycleService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-384: DocumentLifecycleService tests.
 *
 * Covers:
 *   - Pure computation: missing, valid, expiring_soon, expired
 *   - computeFromDate variant
 *   - Integration via DocumentResolverService output (lifecycle_status in API)
 *   - Integration via CompanyDocumentReadModel (lifecycle_status in API)
 */
class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    // ── Pure unit tests ──

    public function test_missing_when_no_upload(): void
    {
        $this->assertEquals(
            DocumentLifecycleService::STATUS_MISSING,
            DocumentLifecycleService::computeStatus(null),
        );
    }

    public function test_valid_when_upload_without_expiration(): void
    {
        $upload = [
            'id' => 1,
            'file_name' => 'doc.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'expires_at' => null,
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->assertEquals(
            DocumentLifecycleService::STATUS_VALID,
            DocumentLifecycleService::computeStatus($upload),
        );
    }

    public function test_valid_when_expiration_far_in_future(): void
    {
        $upload = [
            'id' => 1,
            'file_name' => 'doc.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'expires_at' => now()->addMonths(6)->toIso8601String(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->assertEquals(
            DocumentLifecycleService::STATUS_VALID,
            DocumentLifecycleService::computeStatus($upload),
        );
    }

    public function test_expiring_soon_when_within_threshold(): void
    {
        $upload = [
            'id' => 1,
            'file_name' => 'doc.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'expires_at' => now()->addDays(15)->toIso8601String(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->assertEquals(
            DocumentLifecycleService::STATUS_EXPIRING_SOON,
            DocumentLifecycleService::computeStatus($upload),
        );
    }

    public function test_expiring_soon_at_exact_threshold(): void
    {
        $upload = [
            'id' => 1,
            'file_name' => 'doc.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->assertEquals(
            DocumentLifecycleService::STATUS_EXPIRING_SOON,
            DocumentLifecycleService::computeStatus($upload),
        );
    }

    public function test_expired_when_past(): void
    {
        $upload = [
            'id' => 1,
            'file_name' => 'doc.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'expires_at' => now()->subDay()->toIso8601String(),
            'uploaded_at' => now()->subYear()->toIso8601String(),
        ];

        $this->assertEquals(
            DocumentLifecycleService::STATUS_EXPIRED,
            DocumentLifecycleService::computeStatus($upload),
        );
    }

    public function test_custom_threshold(): void
    {
        // 10 days left, threshold = 7 → valid
        $upload = [
            'id' => 1,
            'file_name' => 'doc.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'expires_at' => now()->addDays(10)->toIso8601String(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->assertEquals(
            DocumentLifecycleService::STATUS_VALID,
            DocumentLifecycleService::computeStatus($upload, expiringSoonDays: 7),
        );

        // Same but threshold = 15 → expiring_soon
        $this->assertEquals(
            DocumentLifecycleService::STATUS_EXPIRING_SOON,
            DocumentLifecycleService::computeStatus($upload, expiringSoonDays: 15),
        );
    }

    // ── computeFromDate variant ──

    public function test_compute_from_date_missing(): void
    {
        $this->assertEquals(
            DocumentLifecycleService::STATUS_MISSING,
            DocumentLifecycleService::computeFromDate(false, null),
        );
    }

    public function test_compute_from_date_valid_no_expiry(): void
    {
        $this->assertEquals(
            DocumentLifecycleService::STATUS_VALID,
            DocumentLifecycleService::computeFromDate(true, null),
        );
    }

    public function test_compute_from_date_expired(): void
    {
        $this->assertEquals(
            DocumentLifecycleService::STATUS_EXPIRED,
            DocumentLifecycleService::computeFromDate(true, Carbon::yesterday()),
        );
    }

    public function test_compute_from_date_expiring_soon(): void
    {
        $this->assertEquals(
            DocumentLifecycleService::STATUS_EXPIRING_SOON,
            DocumentLifecycleService::computeFromDate(true, Carbon::now()->addDays(10)),
        );
    }

    // ── allStatuses ──

    public function test_all_statuses_returns_four(): void
    {
        $statuses = DocumentLifecycleService::allStatuses();
        $this->assertCount(4, $statuses);
        $this->assertContains('missing', $statuses);
        $this->assertContains('valid', $statuses);
        $this->assertContains('expiring_soon', $statuses);
        $this->assertContains('expired', $statuses);
    }

    // ── Integration: lifecycle_status appears in member document API ──

    public function test_lifecycle_status_in_self_document_api(): void
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Lifecycle Co',
            'slug' => 'lifecycle-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $membership = $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($company);
        $this->setupCompanyRbac($company);
        $membership->update(['company_role_id' => CompanyRole::where('company_id', $company->id)->first()?->id]);

        // Activate id_card
        $idCardType = DocumentType::where('code', 'id_card')->first();
        DocumentTypeActivation::updateOrCreate(
            ['company_id' => $company->id, 'document_type_id' => $idCardType->id],
            ['enabled' => true, 'required_override' => false, 'order' => 0],
        );

        // Call API — no upload → lifecycle_status = missing
        $response = $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->getJson('/api/profile/documents');

        $response->assertOk();
        $docs = $response->json('documents');
        $idCard = collect($docs)->firstWhere('code', 'id_card');
        $this->assertNotNull($idCard);
        $this->assertEquals('missing', $idCard['lifecycle_status']);

        // Upload a document with expires_at in the past → expired
        $file = UploadedFile::fake()->create('id.pdf', 100, 'application/pdf');
        $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/profile/documents/id_card', ['file' => $file]);

        // Manually set expires_at to yesterday
        MemberDocument::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('document_type_id', $idCardType->id)
            ->update(['expires_at' => Carbon::yesterday()]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->getJson('/api/profile/documents');

        $docs = $response->json('documents');
        $idCard = collect($docs)->firstWhere('code', 'id_card');
        $this->assertEquals('expired', $idCard['lifecycle_status']);
    }

    // ── Integration: lifecycle_status appears in company document API ──

    public function test_lifecycle_status_in_company_document_api(): void
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Lifecycle Co 2',
            'slug' => 'lifecycle-co-2',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($company);
        $this->setupCompanyRbac($company);

        // Activate kbis
        $kbisType = DocumentType::where('code', 'kbis')->first();
        DocumentTypeActivation::updateOrCreate(
            ['company_id' => $company->id, 'document_type_id' => $kbisType->id],
            ['enabled' => true, 'required_override' => false, 'order' => 0],
        );

        // No upload → lifecycle_status = missing
        $response = $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->getJson('/api/company/documents');

        $response->assertOk();
        $docs = $response->json('documents');
        $kbis = collect($docs)->firstWhere('code', 'kbis');
        $this->assertNotNull($kbis);
        $this->assertEquals('missing', $kbis['lifecycle_status']);
    }
}
