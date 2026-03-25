<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\TagDictionary;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-170 Phase 3: Document role visibility tests.
 *
 * Validates doc_config as override layer:
 * - visible=false hides non-mandatory documents
 * - documents not in doc_config remain visible
 * - no doc_config (null) → all documents visible
 * - order override applies correctly
 */
class DocumentRoleVisibilityTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $admin;
    private User $member;
    private Company $company;
    private CompanyRole $adminRole;
    private $adminMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Doc Visibility Co',
            'slug' => 'doc-visibility-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->activateCompanyModules($this->company);
        $this->adminRole = $this->setUpCompanyRbac($this->company);

        $this->adminMembership = $this->company->memberships()->create([
            'user_id' => $this->admin->id,
            'role' => 'owner',
            'company_role_id' => $this->adminRole->id,
        ]);

        // Activate all document types
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

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // No doc_config → all documents visible
    // ═══════════════════════════════════════════════════════

    public function test_no_doc_config_all_documents_visible(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'no_config',
            'name' => 'No Config',
            'doc_config' => null,
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$membership->id}/documents");

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // All activated documents should be visible
        $this->assertNotEmpty($codes, 'Should have documents when doc_config is null');
        $this->assertContains('id_card', $codes);
        $this->assertContains('driving_license', $codes);
        $this->assertContains('medical_certificate', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // Documents NOT in doc_config remain visible
    // ═══════════════════════════════════════════════════════

    public function test_documents_not_in_doc_config_remain_visible(): void
    {
        // doc_config only mentions medical_certificate, everything else stays visible
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'partial_config',
            'name' => 'Partial Config',
            'archetype' => null,
            'required_tags' => null,
            'doc_config' => [
                ['code' => 'medical_certificate', 'visible' => false],
            ],
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$membership->id}/documents");

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        // medical_certificate hidden, everything else still visible
        $this->assertNotContains('medical_certificate', $codes);
        $this->assertContains('id_card', $codes);
        $this->assertContains('driving_license', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // Order override applied
    // ═══════════════════════════════════════════════════════

    public function test_doc_config_order_override_applied(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'order_test',
            'name' => 'Order Test',
            'doc_config' => [
                // Force medical_certificate to appear first (order=0)
                ['code' => 'medical_certificate', 'visible' => true, 'order' => 0],
                // Force id_card to appear last (order=999)
                ['code' => 'id_card', 'visible' => true, 'order' => 999],
            ],
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$membership->id}/documents");

        $response->assertOk();

        $docs = $response->json('documents');
        $codes = array_column($docs, 'code');

        // medical_certificate should be first, id_card should be last
        $medPos = array_search('medical_certificate', $codes);
        $idPos = array_search('id_card', $codes);

        $this->assertNotFalse($medPos);
        $this->assertNotFalse($idPos);
        $this->assertLessThan($idPos, $medPos, 'medical_certificate (order=0) should appear before id_card (order=999)');
    }

    // ═══════════════════════════════════════════════════════
    // doc_config visible=false hides non-mandatory document (HTTP level)
    // ═══════════════════════════════════════════════════════

    public function test_doc_config_hides_non_mandatory_document_http(): void
    {
        // medical_certificate is NOT mandatory for role 'office' (no required_tags, no tag intersection)
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'office',
            'name' => 'Office Worker',
            'archetype' => null,
            'required_tags' => null,
            'doc_config' => [
                ['code' => 'medical_certificate', 'visible' => false],
                ['code' => 'driving_license', 'visible' => false],
            ],
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$membership->id}/documents");

        $response->assertOk();

        $codes = collect($response->json('documents'))->pluck('code')->toArray();

        $this->assertNotContains('medical_certificate', $codes, 'medical_certificate should be hidden');
        // ADR-390: driving_license no longer has required_by_modules — obligation is tag-based only
        // With no matching tags on this role, driving_license respects doc_config visible=false
        $this->assertNotContains('driving_license', $codes, 'driving_license should be hidden (no tag match, visible=false)');
    }
}
