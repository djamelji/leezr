<?php

namespace Tests\Feature;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-180: Custom Document Type tests.
 *
 * Covers: create, auto-activate, visibility in catalog/member/self/vault,
 * company isolation, platform preset exclusion, archive, delete guards,
 * member document deletion workflow reset.
 */
class CustomDocumentTypeTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $member;
    private Company $company;
    private Company $otherCompany;
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
            'name' => 'Custom Doc Co',
            'slug' => 'custom-doc-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->membership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $adminRole->id,
        ]);

        // Other company for isolation tests
        $otherOwner = User::factory()->create();

        $this->otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->otherCompany);
        $this->setUpCompanyRbac($this->otherCompany);

        $this->otherCompany->memberships()->create([
            'user_id' => $otherOwner->id,
            'role' => 'owner',
        ]);
    }

    private function actAsOwner(?Company $company = null)
    {
        $c = $company ?? $this->company;

        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function createCustomType(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actAsOwner()->postJson('/api/company/document-types/custom', array_merge([
            'label' => 'Attestation Formation',
            'scope' => 'company_user',
            'max_file_size_mb' => 10,
            'accepted_types' => ['pdf', 'jpg'],
            'order' => 5,
            'required' => false,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════
    // 1. Admin creates custom company_user type
    // ═══════════════════════════════════════════════════════

    public function test_admin_creates_custom_company_user_type(): void
    {
        $response = $this->createCustomType();

        $response->assertCreated();
        $response->assertJsonPath('document_type.label', 'Attestation Formation');
        $response->assertJsonPath('document_type.scope', 'company_user');

        $type = DocumentType::where('code', $response->json('document_type.code'))->first();
        $this->assertNotNull($type);
        $this->assertFalse($type->is_system);
        $this->assertEquals($this->company->id, $type->company_id);
        $this->assertStringStartsWith('custom_'.$this->company->id.'_', $type->code);
    }

    // ═══════════════════════════════════════════════════════
    // 2. Admin creates custom company type
    // ═══════════════════════════════════════════════════════

    public function test_admin_creates_custom_company_type(): void
    {
        $response = $this->createCustomType(['scope' => 'company']);

        $response->assertCreated();
        $response->assertJsonPath('document_type.scope', 'company');
    }

    // ═══════════════════════════════════════════════════════
    // 3. Creation auto-creates activation enabled=true
    // ═══════════════════════════════════════════════════════

    public function test_creation_auto_creates_activation(): void
    {
        $response = $this->createCustomType();
        $code = $response->json('document_type.code');

        $type = DocumentType::where('code', $code)->first();
        $activation = DocumentTypeActivation::where('company_id', $this->company->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertNotNull($activation);
        $this->assertTrue($activation->enabled);
    }

    // ═══════════════════════════════════════════════════════
    // 4. required_override set correctly
    // ═══════════════════════════════════════════════════════

    public function test_required_override_set_correctly(): void
    {
        $response = $this->createCustomType(['required' => true]);
        $code = $response->json('document_type.code');

        $type = DocumentType::where('code', $code)->first();
        $activation = DocumentTypeActivation::where('document_type_id', $type->id)->first();

        $this->assertTrue($activation->required_override);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Custom company_user appears in catalog
    // ═══════════════════════════════════════════════════════

    public function test_custom_company_user_in_catalog(): void
    {
        $this->createCustomType();

        $response = $this->actAsOwner()->getJson('/api/company/document-activations');

        $response->assertOk();
        $codes = collect($response->json('company_user_documents'))->pluck('code')->toArray();
        $custom = collect($response->json('company_user_documents'))->firstWhere('is_system', false);

        $this->assertNotNull($custom);
        $this->assertStringStartsWith('custom_', $custom['code']);
    }

    // ═══════════════════════════════════════════════════════
    // 6. Custom company_user in member workflow
    // ═══════════════════════════════════════════════════════

    public function test_custom_company_user_in_member_workflow(): void
    {
        $this->createCustomType();

        $response = $this->actAsOwner()->getJson("/api/company/members/{$this->membership->id}/documents");

        $response->assertOk();
        $codes = collect($response->json('documents'))->pluck('code')->toArray();
        $customCodes = array_filter($codes, fn ($c) => str_starts_with($c, 'custom_'));

        $this->assertNotEmpty($customCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 7. Custom company_user in self flow
    // ═══════════════════════════════════════════════════════

    public function test_custom_company_user_in_self_flow(): void
    {
        $this->createCustomType();

        $response = $this->actingAs($this->member)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/profile/documents');

        $response->assertOk();
        $codes = collect($response->json('documents'))->pluck('code')->toArray();
        $customCodes = array_filter($codes, fn ($c) => str_starts_with($c, 'custom_'));

        $this->assertNotEmpty($customCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 8. Custom company type in vault
    // ═══════════════════════════════════════════════════════

    public function test_custom_company_type_in_vault(): void
    {
        $this->createCustomType(['scope' => 'company']);

        $response = $this->actAsOwner()->getJson('/api/company/documents');

        $response->assertOk();
        $codes = collect($response->json('documents'))->pluck('code')->toArray();
        $customCodes = array_filter($codes, fn ($c) => str_starts_with($c, 'custom_'));

        $this->assertNotEmpty($customCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 9. Other company does not see the custom type
    // ═══════════════════════════════════════════════════════

    public function test_other_company_does_not_see_custom_type(): void
    {
        $this->createCustomType();

        $otherOwner = $this->otherCompany->memberships()->first()->user;

        $response = $this->actingAs($otherOwner)
            ->withHeaders(['X-Company-Id' => $this->otherCompany->id])
            ->getJson('/api/company/document-activations');

        $response->assertOk();
        $allCodes = collect([
            ...$response->json('company_user_documents'),
            ...$response->json('company_documents'),
        ])->pluck('code')->toArray();

        $customCodes = array_filter($allCodes, fn ($c) => str_starts_with($c, 'custom_'));
        $this->assertEmpty($customCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 10. Platform presets exclude custom types
    // ═══════════════════════════════════════════════════════

    public function test_platform_presets_exclude_custom_types(): void
    {
        $this->createCustomType();

        // Verify custom type exists
        $customType = DocumentType::where('is_system', false)->first();
        $this->assertNotNull($customType);

        // Platform jobdomain show — presets should only contain system types
        $platformAdmin = PlatformUser::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'padmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $platformAdmin->roles()->attach($superAdmin);

        $jd = Jobdomain::where('key', 'logistique')->firstOrFail();

        $response = $this->actingAs($platformAdmin, 'platform')->getJson("/api/platform/jobdomains/{$jd->id}");
        $response->assertOk();

        $presetCodes = collect($response->json('document_presets', []))->pluck('code')->toArray();
        $this->assertNotContains($customType->code, $presetCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 11. Archive hides the type everywhere
    // ═══════════════════════════════════════════════════════

    public function test_archive_hides_type_everywhere(): void
    {
        $createResponse = $this->createCustomType();
        $code = $createResponse->json('document_type.code');

        // Archive
        $archiveResponse = $this->actAsOwner()->putJson("/api/company/document-types/custom/{$code}/archive");
        $archiveResponse->assertOk();

        // Verify archived in DB
        $type = DocumentType::where('code', $code)->first();
        $this->assertNotNull($type->archived_at);

        // Verify hidden from catalog
        $catalogResponse = $this->actAsOwner()->getJson('/api/company/document-activations');
        $allCodes = collect([
            ...$catalogResponse->json('company_user_documents'),
            ...$catalogResponse->json('company_documents'),
        ])->pluck('code')->toArray();

        $this->assertNotContains($code, $allCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 12. Delete blocked if documents exist
    // ═══════════════════════════════════════════════════════

    public function test_delete_blocked_if_documents_exist(): void
    {
        $createResponse = $this->createCustomType();
        $code = $createResponse->json('document_type.code');
        $type = DocumentType::where('code', $code)->first();

        // Create a member document referencing this type
        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/test.pdf',
            'file_name' => 'test.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        $response = $this->actAsOwner()->deleteJson("/api/company/document-types/custom/{$code}");
        $response->assertUnprocessable();
    }

    // ═══════════════════════════════════════════════════════
    // 13. Delete OK if zero usage
    // ═══════════════════════════════════════════════════════

    public function test_delete_ok_if_zero_usage(): void
    {
        $createResponse = $this->createCustomType();
        $code = $createResponse->json('document_type.code');

        $response = $this->actAsOwner()->deleteJson("/api/company/document-types/custom/{$code}");
        $response->assertOk();

        $this->assertNull(DocumentType::where('code', $code)->first());
    }

    // ═══════════════════════════════════════════════════════
    // 14. Delete member document resets approved request
    // ═══════════════════════════════════════════════════════

    public function test_delete_member_document_resets_approved_request(): void
    {
        $type = DocumentType::where('is_system', true)
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->first();

        DocumentTypeActivation::updateOrCreate(
            ['company_id' => $this->company->id, 'document_type_id' => $type->id],
            ['enabled' => true, 'required_override' => false, 'order' => 0],
        );

        // Upload a document
        $this->actAsOwner()->postJson(
            "/api/company/members/{$this->membership->id}/documents/{$type->code}",
            ['file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')],
        )->assertOk();

        // Approve it
        $this->actAsOwner()->putJson(
            "/api/company/members/{$this->membership->id}/documents/{$type->code}/review",
            ['status' => 'approved'],
        )->assertOk();

        // Verify it's approved
        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertEquals('approved', $request->status);

        // Delete the document
        $this->actAsOwner()->deleteJson(
            "/api/company/members/{$this->membership->id}/documents/{$type->code}",
        )->assertOk();

        // Verify request reset
        $request->refresh();
        $this->assertEquals('requested', $request->status);
        $this->assertNull($request->reviewer_id);
        $this->assertNull($request->review_note);
        $this->assertNull($request->reviewed_at);
        $this->assertNull($request->submitted_at);
    }

    // ═══════════════════════════════════════════════════════
    // 15. Delete member document resets submitted request
    // ═══════════════════════════════════════════════════════

    public function test_delete_member_document_resets_submitted_request(): void
    {
        $type = DocumentType::where('is_system', true)
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->first();

        DocumentTypeActivation::updateOrCreate(
            ['company_id' => $this->company->id, 'document_type_id' => $type->id],
            ['enabled' => true, 'required_override' => false, 'order' => 0],
        );

        // Upload a document (creates submitted request)
        $this->actAsOwner()->postJson(
            "/api/company/members/{$this->membership->id}/documents/{$type->code}",
            ['file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')],
        )->assertOk();

        $request = DocumentRequest::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first();

        $this->assertEquals('submitted', $request->status);

        // Delete the document
        $this->actAsOwner()->deleteJson(
            "/api/company/members/{$this->membership->id}/documents/{$type->code}",
        )->assertOk();

        // Verify request reset
        $request->refresh();
        $this->assertEquals('requested', $request->status);
        $this->assertNull($request->submitted_at);
    }

    // ═══════════════════════════════════════════════════════
    // 16. Delete member document without request = no error
    // ═══════════════════════════════════════════════════════

    public function test_delete_member_document_without_request_no_error(): void
    {
        $type = DocumentType::where('is_system', true)
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->first();

        DocumentTypeActivation::updateOrCreate(
            ['company_id' => $this->company->id, 'document_type_id' => $type->id],
            ['enabled' => true, 'required_override' => false, 'order' => 0],
        );

        // Create document directly in DB (no request)
        MemberDocument::create([
            'company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'document_type_id' => $type->id,
            'file_path' => 'documents/direct.pdf',
            'file_name' => 'direct.pdf',
            'file_size_bytes' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->owner->id,
        ]);

        // Delete should work fine
        $this->actAsOwner()->deleteJson(
            "/api/company/members/{$this->membership->id}/documents/{$type->code}",
        )->assertOk();

        $this->assertNull(MemberDocument::where('company_id', $this->company->id)
            ->where('user_id', $this->member->id)
            ->where('document_type_id', $type->id)
            ->first());
    }
}
