<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentMandatoryContext;
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
 * ADR-175: Document Activation Catalog tests.
 *
 * Covers: index (grouped, market, enabled/disabled, mandatory),
 * upsert (create, update, disable, market guard, unknown code, membership).
 */
class CompanyDocumentActivationTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Activation Co',
            'slug' => 'activation-co',
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
    }

    private function actAsOwner()
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 1. Index: returns catalog grouped by scope
    // ═══════════════════════════════════════════════════════

    public function test_index_returns_catalog_grouped_by_scope(): void
    {
        $response = $this->actAsOwner()->getJson('/api/company/document-activations');

        $response->assertOk();
        $response->assertJsonStructure([
            'company_user_documents',
            'company_documents',
        ]);

        $this->assertNotEmpty($response->json('company_user_documents'));
        $this->assertNotEmpty($response->json('company_documents'));
    }

    // ═══════════════════════════════════════════════════════
    // 2. Index: respects market filtering
    // ═══════════════════════════════════════════════════════

    public function test_index_respects_market_filtering(): void
    {
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

        $response = $this->actingAs($gbUser)
            ->withHeaders(['X-Company-Id' => $gbCompany->id])
            ->getJson('/api/company/document-activations');

        $response->assertOk();

        // kbis is FR-only → should NOT appear for GB
        $allCodes = collect($response->json('company_user_documents'))
            ->merge($response->json('company_documents'))
            ->pluck('code')
            ->toArray();

        $this->assertNotContains('kbis', $allCodes);
        $this->assertNotContains('id_card', $allCodes);
        // insurance_certificate has no market restriction → present
        $this->assertContains('insurance_certificate', $allCodes);
    }

    // ═══════════════════════════════════════════════════════
    // 3. Index: returns enabled=false when no activation
    // ═══════════════════════════════════════════════════════

    public function test_index_returns_enabled_false_when_no_activation(): void
    {
        // No activations created → all docs exist but enabled=false
        $response = $this->actAsOwner()->getJson('/api/company/document-activations');

        $response->assertOk();

        $allDocs = collect($response->json('company_user_documents'))
            ->merge($response->json('company_documents'));

        foreach ($allDocs as $doc) {
            $this->assertFalse($doc['enabled'], "Expected enabled=false for {$doc['code']}");
        }
    }

    // ═══════════════════════════════════════════════════════
    // 4. Index: returns correct data when activation exists
    // ═══════════════════════════════════════════════════════

    public function test_index_returns_correct_data_when_activation_exists(): void
    {
        $type = DocumentType::where('code', 'kbis')->first();

        DocumentTypeActivation::create([
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'enabled' => true,
            'required_override' => true,
            'order' => 42,
        ]);

        $response = $this->actAsOwner()->getJson('/api/company/document-activations');

        $response->assertOk();

        $kbis = collect($response->json('company_documents'))->firstWhere('code', 'kbis');

        $this->assertNotNull($kbis);
        $this->assertTrue($kbis['enabled']);
        $this->assertTrue($kbis['required_override']);
        $this->assertEquals(42, $kbis['order']);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Upsert: creates activation when absent
    // ═══════════════════════════════════════════════════════

    public function test_upsert_creates_activation_when_absent(): void
    {
        $response = $this->actAsOwner()
            ->putJson('/api/company/document-activations/kbis', [
                'enabled' => true,
                'required_override' => false,
                'order' => 10,
            ]);

        $response->assertOk();
        $response->assertJsonPath('activation.code', 'kbis');
        $response->assertJsonPath('activation.enabled', true);
        $response->assertJsonPath('activation.order', 10);

        $type = DocumentType::where('code', 'kbis')->first();

        $this->assertDatabaseHas('document_type_activations', [
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'enabled' => true,
            'required_override' => false,
            'order' => 10,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 6. Upsert: updates existing activation
    // ═══════════════════════════════════════════════════════

    public function test_upsert_updates_existing_activation(): void
    {
        $type = DocumentType::where('code', 'kbis')->first();

        DocumentTypeActivation::create([
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'enabled' => true,
            'required_override' => false,
            'order' => 5,
        ]);

        $response = $this->actAsOwner()
            ->putJson('/api/company/document-activations/kbis', [
                'enabled' => true,
                'required_override' => true,
                'order' => 99,
            ]);

        $response->assertOk();
        $response->assertJsonPath('activation.required_override', true);
        $response->assertJsonPath('activation.order', 99);

        $this->assertDatabaseHas('document_type_activations', [
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'required_override' => true,
            'order' => 99,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 7. Upsert: can disable a document
    // ═══════════════════════════════════════════════════════

    public function test_upsert_can_disable_document(): void
    {
        $type = DocumentType::where('code', 'insurance_certificate')->first();

        DocumentTypeActivation::create([
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'enabled' => true,
            'order' => 0,
        ]);

        $response = $this->actAsOwner()
            ->putJson('/api/company/document-activations/insurance_certificate', [
                'enabled' => false,
                'required_override' => false,
                'order' => 0,
            ]);

        $response->assertOk();
        $response->assertJsonPath('activation.enabled', false);

        $this->assertDatabaseHas('document_type_activations', [
            'company_id' => $this->company->id,
            'document_type_id' => $type->id,
            'enabled' => false,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // 8. Upsert: rejects type outside company market
    // ═══════════════════════════════════════════════════════

    public function test_upsert_rejects_type_outside_company_market(): void
    {
        // Create a GB company
        $gbCompany = Company::create([
            'name' => 'GB Co',
            'slug' => 'gb-co-2',
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

        // kbis is FR-only → should reject for GB company
        $response = $this->actingAs($gbUser)
            ->withHeaders(['X-Company-Id' => $gbCompany->id])
            ->putJson('/api/company/document-activations/kbis', [
                'enabled' => true,
                'required_override' => false,
                'order' => 0,
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // 9. Upsert: rejects unknown code
    // ═══════════════════════════════════════════════════════

    public function test_upsert_rejects_unknown_code(): void
    {
        $response = $this->actAsOwner()
            ->putJson('/api/company/document-activations/nonexistent_doc', [
                'enabled' => true,
                'required_override' => false,
                'order' => 0,
            ]);

        $response->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════
    // 10. Upsert: requires membership
    // ═══════════════════════════════════════════════════════

    public function test_upsert_requires_membership(): void
    {
        $outsider = User::factory()->create();

        // outsider has no membership in this company
        // The middleware should reject before reaching the use case
        $response = $this->actingAs($outsider)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/company/document-activations/kbis', [
                'enabled' => true,
                'required_override' => false,
                'order' => 0,
            ]);

        // 403 or 404 depending on middleware behavior
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    // ═══════════════════════════════════════════════════════
    // 11. ReadModel: preserves mandatory flag
    // ═══════════════════════════════════════════════════════

    public function test_read_model_preserves_mandatory_documents(): void
    {
        // insurance_certificate is mandatory for logistique jobdomain
        $response = $this->actAsOwner()->getJson('/api/company/document-activations');

        $response->assertOk();

        $insurance = collect($response->json('company_documents'))
            ->firstWhere('code', 'insurance_certificate');

        $this->assertNotNull($insurance);
        $this->assertTrue($insurance['mandatory']);

        // id_card is also mandatory for logistique jobdomain (FR)
        $idCard = collect($response->json('company_user_documents'))
            ->firstWhere('code', 'id_card');

        $this->assertNotNull($idCard);
        $this->assertTrue($idCard['mandatory']);
    }
}
