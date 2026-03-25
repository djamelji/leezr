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
 * ADR-170 Phase 3 — SECURITY TESTS.
 *
 * These tests validate that mandatory documents CANNOT be hidden or
 * made optional via doc_config. This is a non-negotiable security invariant.
 *
 * A mandatory document must ALWAYS be visible and ALWAYS be required,
 * regardless of any doc_config override.
 */
class DocumentMandatoryCannotBeHiddenTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $admin;
    private User $member;
    private Company $company;
    private CompanyRole $adminRole;
    private CompanyRole $driverRole;
    private $adminMembership;
    private $memberMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Doc Security Co',
            'slug' => 'doc-security-co',
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
    // SECURITY TEST 1: mandatory doc + visible=false → REMAINS visible
    // ═══════════════════════════════════════════════════════

    public function test_mandatory_document_cannot_be_hidden_by_doc_config(): void
    {
        // driving_license is mandatory for driver via tags=['driving'] + required_tags=['driving']
        // doc_config tries to hide it → must be IGNORED
        $this->driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
            'doc_config' => [
                ['code' => 'driving_license', 'visible' => false],
            ],
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}/documents");

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $drivingLicense = $docs->firstWhere('code', 'driving_license');

        $this->assertNotNull(
            $drivingLicense,
            'SECURITY: driving_license (mandatory) must remain visible even with doc_config visible=false'
        );
        $this->assertTrue(
            $drivingLicense['mandatory'],
            'SECURITY: driving_license must be mandatory for driver role'
        );
        $this->assertTrue(
            $drivingLicense['required'],
            'SECURITY: driving_license must remain required even with doc_config visible=false'
        );
    }

    // ═══════════════════════════════════════════════════════
    // SECURITY TEST 2: mandatory doc + required=false → REMAINS required
    // ═══════════════════════════════════════════════════════

    public function test_mandatory_document_cannot_be_made_optional_by_doc_config(): void
    {
        $this->driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
            'doc_config' => [
                ['code' => 'driving_license', 'visible' => true, 'required' => false],
            ],
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}/documents");

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $drivingLicense = $docs->firstWhere('code', 'driving_license');

        $this->assertNotNull($drivingLicense);
        $this->assertTrue(
            $drivingLicense['required'],
            'SECURITY: mandatory document must remain required even with doc_config required=false'
        );
    }

    // ═══════════════════════════════════════════════════════
    // SECURITY TEST 3: mandatory doc + visible=false AND required=false → visible AND required
    // ═══════════════════════════════════════════════════════

    public function test_mandatory_document_survives_both_overrides(): void
    {
        $this->driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
            'doc_config' => [
                ['code' => 'driving_license', 'visible' => false, 'required' => false],
            ],
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}/documents");

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $drivingLicense = $docs->firstWhere('code', 'driving_license');

        $this->assertNotNull(
            $drivingLicense,
            'SECURITY: mandatory document must remain visible even with visible=false AND required=false'
        );
        $this->assertTrue(
            $drivingLicense['required'],
            'SECURITY: mandatory document must remain required even with visible=false AND required=false'
        );
    }

    // ═══════════════════════════════════════════════════════
    // CONTROL TEST 4: non-mandatory doc + visible=false → correctly hidden
    // ═══════════════════════════════════════════════════════

    public function test_non_mandatory_document_can_be_hidden_by_doc_config(): void
    {
        // medical_certificate: has tags=['driving']
        // but for role 'basic' (no archetype, no required_tags), it is NOT mandatory
        // (no required_by_modules, no tag intersection)
        $basicRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'basic',
            'name' => 'Basic',
            'archetype' => null,
            'required_tags' => null,
            'doc_config' => [
                ['code' => 'medical_certificate', 'visible' => false],
            ],
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $basicRole->id,
        ]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}/documents");

        $response->assertOk();

        $docs = collect($response->json('documents'));
        $medCert = $docs->firstWhere('code', 'medical_certificate');

        $this->assertNull(
            $medCert,
            'CONTROL: non-mandatory document with visible=false should be hidden'
        );
    }
}
