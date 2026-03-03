<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Models\Company;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDocumentTypeCatalogTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'docadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ─── Index ──────────────────────────────────────────

    public function test_index_returns_all_types_with_counts(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/documents');

        $response->assertOk()
            ->assertJsonStructure([
                'document_types' => [
                    '*' => [
                        'id', 'code', 'label', 'scope', 'validation_rules',
                        'is_archived', 'activations_count',
                        'member_documents_count', 'company_documents_count',
                    ],
                ],
            ]);

        // PlatformSeeder syncs 6 system types
        $this->assertGreaterThanOrEqual(6, count($response->json('document_types')));
    }

    // ─── Show ───────────────────────────────────────────

    public function test_show_returns_enriched_detail(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/documents/{$type->id}");

        $response->assertOk()
            ->assertJsonPath('document_type.code', 'id_card')
            ->assertJsonStructure([
                'document_type' => [
                    'id', 'code', 'label', 'scope', 'validation_rules',
                    'is_archived', 'activations_count',
                    'member_documents_count', 'company_documents_count',
                    'requests_count', 'jobdomain_presets',
                ],
            ]);
    }

    // ─── Store ──────────────────────────────────────────

    public function test_store_creates_system_type(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/documents', [
                'code' => 'work_permit',
                'label' => 'Work Permit',
                'scope' => 'company_user',
                'validation_rules' => [
                    'max_file_size_mb' => 5,
                    'accepted_types' => ['pdf'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('document_type.code', 'work_permit')
            ->assertJsonPath('document_type.is_system', true);

        $this->assertDatabaseHas('document_types', [
            'code' => 'work_permit',
            'is_system' => true,
            'company_id' => null,
        ]);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/documents', [
                'code' => 'id_card',
                'label' => 'Duplicate',
                'scope' => 'company_user',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_validates_code_format(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/documents', [
                'code' => 'Invalid-Code!',
                'label' => 'Bad Code',
                'scope' => 'company_user',
            ]);

        $response->assertStatus(422);
    }

    // ─── Update ─────────────────────────────────────────

    public function test_update_mutable_fields(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/documents/{$type->id}", [
                'label' => 'National Identity Card',
                'validation_rules' => [
                    'max_file_size_mb' => 15,
                    'accepted_types' => ['pdf', 'png'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('document_type.label', 'National Identity Card');

        $type->refresh();
        $this->assertEquals('National Identity Card', $type->label);
        $this->assertEquals(15, $type->validation_rules['max_file_size_mb']);
    }

    public function test_update_rejects_code_change(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();

        // code is not in validated fields — it simply gets ignored
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/documents/{$type->id}", [
                'code' => 'renamed_card',
                'label' => 'Renamed',
            ]);

        $response->assertOk();

        $type->refresh();
        $this->assertEquals('id_card', $type->code); // code unchanged
    }

    public function test_update_rejects_scope_change(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();

        // scope is not in validated fields — it simply gets ignored
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/documents/{$type->id}", [
                'scope' => 'company',
                'label' => 'New Label',
            ]);

        $response->assertOk();

        $type->refresh();
        $this->assertEquals('company_user', $type->scope); // scope unchanged
    }

    // ─── Archive / Restore ──────────────────────────────

    public function test_archive_sets_archived_at_and_disables_activations(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();

        // Create an activation for a company
        $company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co-' . uniqid(),
            'jobdomain_key' => 'logistique',
        ]);
        DocumentTypeActivation::create([
            'company_id' => $company->id,
            'document_type_id' => $type->id,
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/documents/{$type->id}/archive");

        $response->assertOk();

        $type->refresh();
        $this->assertNotNull($type->archived_at);

        // Activation should be disabled
        $activation = DocumentTypeActivation::where('document_type_id', $type->id)
            ->where('company_id', $company->id)
            ->first();

        $this->assertFalse($activation->enabled);
    }

    public function test_restore_clears_archived_at(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();
        $type->update(['archived_at' => now()]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/documents/{$type->id}/restore");

        $response->assertOk();

        $type->refresh();
        $this->assertNull($type->archived_at);
    }

    // ─── Sync ───────────────────────────────────────────

    public function test_sync_only_creates_missing_types(): void
    {
        $beforeCount = DocumentType::where('is_system', true)->count();

        // Delete one to simulate missing
        DocumentType::where('code', 'id_card')->delete();

        $afterDeleteCount = DocumentType::where('is_system', true)->count();
        $this->assertEquals($beforeCount - 1, $afterDeleteCount);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/documents/sync');

        $response->assertOk();

        // Should be back to original count
        $afterSyncCount = DocumentType::where('is_system', true)->count();
        $this->assertEquals($beforeCount, $afterSyncCount);
    }

    public function test_sync_does_not_overwrite_existing_types(): void
    {
        $type = DocumentType::where('code', 'id_card')->firstOrFail();
        $type->update(['label' => 'My Custom Label']);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/documents/sync');

        $response->assertOk();

        $type->refresh();
        $this->assertEquals('My Custom Label', $type->label); // Not overwritten
    }

    // ─── Auth / Permission ──────────────────────────────

    public function test_requires_platform_auth(): void
    {
        $response = $this->getJson('/api/platform/documents');

        $response->assertUnauthorized();
    }

    public function test_requires_manage_document_catalog_permission(): void
    {
        // Create a platform user without the super_admin role
        $limitedUser = PlatformUser::create([
            'first_name' => 'Limited',
            'last_name' => 'User',
            'email' => 'limited@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        // Assign a role that does NOT have manage_document_catalog
        $viewerRole = PlatformRole::where('key', '!=', 'super_admin')->first();
        if ($viewerRole) {
            $limitedUser->roles()->attach($viewerRole);
        }

        $response = $this->actingAs($limitedUser, 'platform')
            ->getJson('/api/platform/documents');

        $response->assertForbidden();
    }
}
