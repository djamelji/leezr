<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformJobdomainTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ─── CRUD ────────────────────────────────────────────

    public function test_can_list_jobdomains(): void
    {
        Jobdomain::create([
            'key' => 'test_jd',
            'label' => 'Test JD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/jobdomains');

        // ADR-167a: PlatformSeeder seeds 'logistique' + we created 'test_jd' = 2
        $response->assertOk()
            ->assertJsonStructure(['jobdomains'])
            ->assertJsonCount(2, 'jobdomains');
    }

    public function test_can_show_jobdomain(): void
    {
        $jd = Jobdomain::create([
            'key' => 'show_me',
            'label' => 'Show Me',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk()
            ->assertJsonPath('jobdomain.key', 'show_me')
            ->assertJsonPath('jobdomain.label', 'Show Me');
    }

    public function test_can_create_jobdomain(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'coiffure',
                'label' => 'Coiffure',
                'description' => 'Hair salons',
                'default_modules' => ['core.members'],
                'default_fields' => [
                    ['code' => 'siret', 'order' => 0],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('jobdomain.key', 'coiffure')
            ->assertJsonPath('jobdomain.label', 'Coiffure');

        $this->assertDatabaseHas('jobdomains', ['key' => 'coiffure']);
    }

    public function test_cannot_create_duplicate_key(): void
    {
        Jobdomain::create([
            'key' => 'duplicate_key',
            'label' => 'Original',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'duplicate_key',
                'label' => 'Duplicate',
            ]);

        $response->assertStatus(422);
    }

    public function test_can_update_jobdomain(): void
    {
        $jd = Jobdomain::create([
            'key' => 'update_me',
            'label' => 'Old Label',
            'is_active' => true,
        ]);

        // ADR-169: default_fields = code + order only (no 'required')
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'label' => 'New Label',
                'description' => 'Updated desc',
                'default_modules' => ['core.members', 'core.settings'],
                'default_fields' => [
                    ['code' => 'siret', 'order' => 0],
                    ['code' => 'phone', 'order' => 1],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('jobdomain.label', 'New Label');

        $jd->refresh();
        $this->assertEquals(['core.members', 'core.settings'], $jd->default_modules);
        $this->assertCount(2, $jd->default_fields);
        $this->assertEquals('siret', $jd->default_fields[0]['code']);
        $this->assertEquals(0, $jd->default_fields[0]['order']);
        $this->assertEquals('phone', $jd->default_fields[1]['code']);
    }

    public function test_can_delete_unassigned_jobdomain(): void
    {
        $jd = Jobdomain::create([
            'key' => 'delete_me',
            'label' => 'Delete Me',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('jobdomains', ['key' => 'delete_me']);
    }

    public function test_cannot_delete_if_assigned_to_company(): void
    {
        $jd = Jobdomain::create([
            'key' => 'assigned_jd',
            'label' => 'Assigned',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company->jobdomains()->sync([$jd->id]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete: this job domain is assigned to 1 company(ies).');
    }

    // ─── Field preset validation ─────────────────────────

    public function test_default_fields_reject_platform_user_scope(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'bad_fields',
                'label' => 'Bad Fields',
                'default_fields' => [
                    ['code' => 'internal_note', 'required' => false, 'order' => 0],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_default_fields_reject_nonexistent_code(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/jobdomains', [
                'key' => 'bad_codes',
                'label' => 'Bad Codes',
                'default_fields' => [
                    ['code' => 'nonexistent_field_xyz', 'required' => false, 'order' => 0],
                ],
            ]);

        $response->assertStatus(422);
    }

    // ─── Preset application ──────────────────────────────

    public function test_assign_applies_db_preset_fields(): void
    {
        $jd = Jobdomain::create([
            'key' => 'preset_test',
            'label' => 'Preset Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [
                ['code' => 'siret', 'required' => true, 'order' => 0],
                ['code' => 'phone', 'required' => false, 'order' => 1],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Preset Co', 'slug' => 'preset-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'preset_test');

        $activations = FieldActivation::where('company_id', $company->id)->get();
        $activatedCodes = $activations->map(function ($a) {
            return FieldDefinition::find($a->field_definition_id)->code;
        })->toArray();

        $this->assertContains('siret', $activatedCodes);
        $this->assertContains('phone', $activatedCodes);
    }

    public function test_assign_applies_order_from_preset(): void
    {
        // ADR-169: presets control activation + order only (no required — catalog handles mandatory)
        $jd = Jobdomain::create([
            'key' => 'structured_test',
            'label' => 'Structured Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [
                ['code' => 'siret', 'order' => 5],
                ['code' => 'phone', 'order' => 10],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Structured Co', 'slug' => 'structured-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'structured_test');

        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $siretActivation = FieldActivation::where('company_id', $company->id)
            ->where('field_definition_id', $siretDef->id)
            ->first();

        // ADR-169: required_override is always false (catalog handles mandatory via MandatoryContext)
        $this->assertFalse($siretActivation->required_override);
        $this->assertEquals(5, $siretActivation->order);

        $phoneDef = FieldDefinition::where('code', 'phone')->first();
        $phoneActivation = FieldActivation::where('company_id', $company->id)
            ->where('field_definition_id', $phoneDef->id)
            ->first();

        $this->assertFalse($phoneActivation->required_override);
        $this->assertEquals(10, $phoneActivation->order);
    }

    public function test_updating_presets_does_not_modify_existing_companies(): void
    {
        // ADR-169: default_fields = code + order only
        $jd = Jobdomain::create([
            'key' => 'isolation_test',
            'label' => 'Isolation Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [
                ['code' => 'siret', 'order' => 0],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Isolation Co', 'slug' => 'isolation-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'isolation_test');

        $countBefore = FieldActivation::where('company_id', $company->id)->count();

        // Now update jobdomain presets to also include phone
        $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'default_fields' => [
                    ['code' => 'siret', 'order' => 0],
                    ['code' => 'phone', 'order' => 1],
                ],
            ]);

        // Company activations should NOT have changed
        $countAfter = FieldActivation::where('company_id', $company->id)->count();
        $this->assertEquals($countBefore, $countAfter);
    }

    // ─── Permission ──────────────────────────────────────

    // ─── Document Presets (ADR-178) ─────────────────────

    public function test_show_includes_document_presets(): void
    {
        DocumentTypeCatalog::sync();

        $jd = Jobdomain::where('key', 'logistique')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk()
            ->assertJsonStructure(['document_presets']);

        $presets = $response->json('document_presets');

        // DocumentTypeCatalog has 6 types total
        $this->assertCount(6, $presets);

        // 5 are in the logistique preset (id_card, driving_license, medical_certificate, kbis, insurance_certificate)
        $inPreset = collect($presets)->where('is_in_preset', true);
        $this->assertCount(5, $inPreset);

        // Verify structure of each item
        foreach ($presets as $preset) {
            $this->assertArrayHasKey('code', $preset);
            $this->assertArrayHasKey('label', $preset);
            $this->assertArrayHasKey('scope', $preset);
            $this->assertArrayHasKey('max_file_size_mb', $preset);
            $this->assertArrayHasKey('accepted_types', $preset);
            $this->assertArrayHasKey('is_in_preset', $preset);
            $this->assertArrayHasKey('mandatory_for_jobdomain', $preset);
            $this->assertArrayHasKey('preset_order', $preset);
            $this->assertArrayHasKey('applicable_markets', $preset);
        }

        // id_card should be mandatory for logistique, scope company_user, in preset
        $idCard = collect($presets)->firstWhere('code', 'id_card');
        $this->assertTrue($idCard['mandatory_for_jobdomain']);
        $this->assertEquals('company_user', $idCard['scope']);
        $this->assertTrue($idCard['is_in_preset']);

        // kbis should be in preset with scope company
        $kbis = collect($presets)->firstWhere('code', 'kbis');
        $this->assertEquals('company', $kbis['scope']);
        $this->assertTrue($kbis['is_in_preset']);

        // transport_license should NOT be in preset (not in logistique default_documents)
        $transportLicense = collect($presets)->firstWhere('code', 'transport_license');
        $this->assertFalse($transportLicense['is_in_preset']);
    }

    // ─── Document Presets CRUD (ADR-179) ────────────────

    public function test_can_update_default_documents(): void
    {
        DocumentTypeCatalog::sync();

        $jd = Jobdomain::create([
            'key' => 'doc_update_test',
            'label' => 'Doc Update Test',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'default_documents' => [
                    ['code' => 'id_card', 'order' => 0],
                    ['code' => 'kbis', 'order' => 10],
                ],
            ]);

        $response->assertOk();

        $jd->refresh();
        $this->assertCount(2, $jd->default_documents);
        $this->assertEquals('id_card', $jd->default_documents[0]['code']);
        $this->assertEquals(0, $jd->default_documents[0]['order']);
        $this->assertEquals('kbis', $jd->default_documents[1]['code']);
        $this->assertEquals(10, $jd->default_documents[1]['order']);
    }

    public function test_default_documents_reject_nonexistent_code(): void
    {
        DocumentTypeCatalog::sync();

        $jd = Jobdomain::create([
            'key' => 'doc_bad_code',
            'label' => 'Bad Code Test',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'default_documents' => [
                    ['code' => 'nonexistent_xyz'],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_default_documents_reject_non_system_types(): void
    {
        DocumentTypeCatalog::sync();

        // Create a non-system document type
        DocumentType::create([
            'code' => 'custom_type_test',
            'label' => 'Custom Type',
            'scope' => 'company',
            'is_system' => false,
        ]);

        $jd = Jobdomain::create([
            'key' => 'doc_non_sys',
            'label' => 'Non System Test',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$jd->id}", [
                'default_documents' => [
                    ['code' => 'custom_type_test'],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_show_reads_document_presets_from_db(): void
    {
        DocumentTypeCatalog::sync();

        $jd = Jobdomain::where('key', 'logistique')->first();

        // Override default_documents in DB to only have 2 types
        $jd->update([
            'default_documents' => [
                ['code' => 'id_card', 'order' => 5],
                ['code' => 'kbis', 'order' => 15],
            ],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk();

        $presets = $response->json('document_presets');

        // Only 2 should be in preset (from DB override, not registry's 5)
        $inPreset = collect($presets)->where('is_in_preset', true);
        $this->assertCount(2, $inPreset);

        // id_card should have order 5
        $idCard = collect($presets)->firstWhere('code', 'id_card');
        $this->assertTrue($idCard['is_in_preset']);
        $this->assertEquals(5, $idCard['preset_order']);

        // kbis should have order 15
        $kbis = collect($presets)->firstWhere('code', 'kbis');
        $this->assertTrue($kbis['is_in_preset']);
        $this->assertEquals(15, $kbis['preset_order']);

        // driving_license should NOT be in preset (removed from DB override)
        $drivingLicense = collect($presets)->firstWhere('code', 'driving_license');
        $this->assertFalse($drivingLicense['is_in_preset']);
    }

    public function test_assign_uses_db_document_presets(): void
    {
        DocumentTypeCatalog::sync();

        $jd = Jobdomain::create([
            'key' => 'doc_assign_test',
            'label' => 'Doc Assign Test',
            'is_active' => true,
            'default_modules' => [],
            'default_fields' => [],
            'default_documents' => [
                ['code' => 'id_card', 'order' => 0],
                ['code' => 'kbis', 'order' => 10],
            ],
        ]);

        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Doc Assign Co', 'slug' => 'doc-assign-co', 'jobdomain_key' => 'logistique']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        JobdomainGate::assignToCompany($company, 'doc_assign_test');

        $activations = DocumentTypeActivation::where('company_id', $company->id)->get();
        $activatedCodes = $activations->map(fn ($a) => DocumentType::find($a->document_type_id)->code)->toArray();

        $this->assertContains('id_card', $activatedCodes);
        $this->assertContains('kbis', $activatedCodes);
        $this->assertCount(2, $activatedCodes);
    }

    // ─── Permission ──────────────────────────────────────

    public function test_requires_manage_jobdomains_permission(): void
    {
        $unprivileged = PlatformUser::create([
            'first_name' => 'No',
            'last_name' => 'Perms',
            'email' => 'noperms@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response = $this->actingAs($unprivileged, 'platform')
            ->getJson('/api/platform/jobdomains');

        $response->assertStatus(403);
    }
}
