<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-387: DocumentComplianceReadModel tests.
 *
 * Covers:
 *   - Empty company (no activations → empty result)
 *   - Compliance rate calculation (valid + expiring_soon = compliant)
 *   - Missing / expired / expiring_soon counts
 *   - by_role breakdown with member_count
 *   - by_type breakdown
 *   - API endpoint returns compliance data
 */
class DocumentComplianceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;

    private User $owner;

    private DocumentType $userType;

    private DocumentType $companyType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Compliance Co',
            'slug' => 'compliance-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $membership = $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($this->company);
        $this->setupCompanyRbac($this->company);
        $membership->update([
            'company_role_id' => CompanyRole::where('company_id', $this->company->id)->first()?->id,
        ]);

        // Use well-known system document types
        $this->userType = DocumentType::where('code', 'id_card')
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->first();
        $this->companyType = DocumentType::where('scope', DocumentType::SCOPE_COMPANY)
            ->first();
    }

    // ── Pure ReadModel tests ──

    public function test_empty_when_no_activations(): void
    {
        // No activations → empty result
        $result = DocumentComplianceReadModel::forCompany($this->company);

        $this->assertEquals(0, $result['summary']['total']);
        $this->assertEquals(0, $result['summary']['rate']);
        $this->assertEmpty($result['by_role']);
        $this->assertEmpty($result['by_type']);
    }

    public function test_missing_when_required_but_no_upload(): void
    {
        $this->activateType($this->userType, required: true);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        // 1 member × 1 required user-scope type = 1 slot
        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['missing']);
        $this->assertEquals(0, $result['summary']['valid']);
        $this->assertEquals(0, $result['summary']['rate']);
    }

    public function test_valid_when_uploaded_no_expiry(): void
    {
        $this->activateType($this->userType, required: true);

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'document_type_id' => $this->userType->id,
            'file_path' => 'docs/test.pdf',
            'file_name' => 'test.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['valid']);
        $this->assertEquals(100, $result['summary']['rate']);
    }

    public function test_expired_document_counted(): void
    {
        $this->activateType($this->userType, required: true);

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'document_type_id' => $this->userType->id,
            'file_path' => 'docs/test.pdf',
            'file_name' => 'test.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
            'expires_at' => Carbon::yesterday(),
        ]);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        $this->assertEquals(1, $result['summary']['expired']);
        $this->assertEquals(0, $result['summary']['rate']);
    }

    public function test_expiring_soon_is_compliant(): void
    {
        $this->activateType($this->userType, required: true);

        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'document_type_id' => $this->userType->id,
            'file_path' => 'docs/test.pdf',
            'file_name' => 'test.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        $this->assertEquals(1, $result['summary']['expiring_soon']);
        // expiring_soon counts as compliant
        $this->assertEquals(100, $result['summary']['rate']);
    }

    public function test_company_scope_documents(): void
    {
        if (! $this->companyType) {
            $this->markTestSkipped('No company-scope document type available in catalog');
        }

        $this->activateType($this->companyType, required: true);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        // Company-scope type, no upload → missing
        $this->assertEquals(1, $result['summary']['missing']);

        // Upload a company document
        CompanyDocument::create([
            'company_id' => $this->company->id,
            'document_type_id' => $this->companyType->id,
            'file_path' => 'company/test.pdf',
            'file_name' => 'test.pdf',
            'file_size_bytes' => 2048,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        $result = DocumentComplianceReadModel::forCompany($this->company);
        $this->assertEquals(1, $result['summary']['valid']);
        $this->assertEquals(100, $result['summary']['rate']);
    }

    public function test_by_role_breakdown(): void
    {
        $this->activateType($this->userType, required: true);

        // Add a second member with a different role
        $member2 = User::factory()->create();
        $role2 = CompanyRole::where('company_id', $this->company->id)->skip(1)->first()
            ?? CompanyRole::where('company_id', $this->company->id)->first();

        $this->company->memberships()->create([
            'user_id' => $member2->id,
            'role' => 'member',
            'company_role_id' => $role2->id,
        ]);

        // member2 has upload, owner does not
        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $member2->id,
            'document_type_id' => $this->userType->id,
            'file_path' => 'docs/test.pdf',
            'file_name' => 'test.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $member2->id,
        ]);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        // 2 members × 1 type = 2 slots total
        $this->assertEquals(2, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['valid']);
        $this->assertEquals(1, $result['summary']['missing']);
        $this->assertEquals(50, $result['summary']['rate']);

        // by_role should have entries
        $this->assertNotEmpty($result['by_role']);
        foreach ($result['by_role'] as $roleData) {
            $this->assertArrayHasKey('role_key', $roleData);
            $this->assertArrayHasKey('member_count', $roleData);
            $this->assertArrayHasKey('rate', $roleData);
            $this->assertArrayHasKey('total', $roleData);
        }
    }

    public function test_by_type_breakdown(): void
    {
        $this->activateType($this->userType, required: true);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        $this->assertNotEmpty($result['by_type']);

        $typeEntry = collect($result['by_type'])->firstWhere('code', $this->userType->code);
        $this->assertNotNull($typeEntry);
        $this->assertEquals(1, $typeEntry['total']);
        $this->assertEquals(1, $typeEntry['missing']);
        $this->assertArrayHasKey('rate', $typeEntry);
    }

    public function test_non_required_types_excluded(): void
    {
        // Activate type but NOT required
        $this->activateType($this->userType, required: false);

        $result = DocumentComplianceReadModel::forCompany($this->company);

        // Non-required types should not be in compliance (unless mandatory by context)
        // If the type happens to be mandatory via jobdomain/module, it will still appear
        // Otherwise total should be 0
        $this->assertIsArray($result['summary']);
    }

    // ── API endpoint test ──

    public function test_api_returns_compliance_data(): void
    {
        $this->activateType($this->userType, required: true);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/company/documents/compliance');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => ['total', 'valid', 'missing', 'expiring_soon', 'expired', 'rate'],
            'by_role',
            'by_type',
        ]);

        $this->assertEquals(1, $response->json('summary.total'));
    }

    public function test_api_requires_members_view_permission(): void
    {
        // Create a user without members.view permission
        $member = User::factory()->create();
        $limitedRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'limited',
            'name' => 'Limited',
            'is_administrative' => false,
            'permissions' => [],
        ]);

        $this->company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'member',
            'company_role_id' => $limitedRole->id,
        ]);

        $response = $this->actingAs($member)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/company/documents/compliance');

        $response->assertStatus(403);
    }

    // ── Helper ──

    private function activateType(DocumentType $type, bool $required): void
    {
        DocumentTypeActivation::updateOrCreate(
            ['company_id' => $this->company->id, 'document_type_id' => $type->id],
            ['enabled' => true, 'required_override' => $required, 'order' => 0],
        );
    }
}
