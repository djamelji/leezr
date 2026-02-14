<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValue;
use App\Core\Fields\FieldWriteService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FieldValueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;
    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        // Company context
        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Activate company fields
        $companyFields = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY)->get();
        foreach ($companyFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'required_override' => $field->code === 'siret',
                'order' => $index * 10,
            ]);
        }

        // Activate company_user fields
        $cuFields = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();
        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }

        // Platform admin
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        // Activate platform_user fields
        $puFields = FieldDefinition::where('scope', FieldDefinition::SCOPE_PLATFORM_USER)->get();
        foreach ($puFields as $index => $field) {
            FieldActivation::create([
                'company_id' => null,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }
    }

    // ─── Company dynamic fields via update endpoint ───────

    public function test_company_update_saves_dynamic_fields(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/company', [
                'name' => 'Updated Co',
                'dynamic_fields' => [
                    'siret' => '12345678901234',
                    'vat_number' => 'FR12345678',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('base_fields.name', 'Updated Co');

        // Check values were written
        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $this->assertDatabaseHas('field_values', [
            'field_definition_id' => $siretDef->id,
            'model_type' => 'company',
            'model_id' => $this->company->id,
        ]);
    }

    // ─── Partial update preserves existing values ─────────

    public function test_partial_update_preserves_existing_values(): void
    {
        // Write initial values for 3 fields
        FieldWriteService::upsert(
            $this->company,
            ['siret' => '11111111111111', 'vat_number' => 'FR11111', 'legal_form' => 'SAS'],
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        // Update only siret
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/company', [
                'name' => $this->company->name,
                'dynamic_fields' => [
                    'siret' => '22222222222222',
                ],
            ]);

        $response->assertOk();

        // siret should be updated
        $resolved = FieldResolverService::resolve(
            $this->company,
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        $values = collect($resolved)->pluck('value', 'code');
        $this->assertEquals('22222222222222', $values['siret']);
        $this->assertEquals('FR11111', $values['vat_number']);
        $this->assertEquals('SAS', $values['legal_form']);
    }

    public function test_update_without_dynamic_fields_preserves_all_values(): void
    {
        // Write initial values
        FieldWriteService::upsert(
            $this->company,
            ['siret' => '99999999999999'],
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        // Update without dynamic_fields key
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/company', [
                'name' => 'Name Only Update',
            ]);

        $response->assertOk();

        // siret should still exist
        $resolved = FieldResolverService::resolve(
            $this->company,
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        $siret = collect($resolved)->firstWhere('code', 'siret');
        $this->assertEquals('99999999999999', $siret['value']);
    }

    // ─── Required override validation ─────────────────────

    public function test_required_override_validation(): void
    {
        // siret has required_override = true in activation
        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/company', [
                'name' => $this->company->name,
                'dynamic_fields' => [
                    'siret' => '', // Empty value for required field
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dynamic_fields.siret']);
    }

    // ─── FieldResolverService uses max 3 queries ──────────

    public function test_resolver_uses_max_3_queries(): void
    {
        // Write some values first
        FieldWriteService::upsert(
            $this->company,
            ['siret' => '12345678901234', 'vat_number' => 'FR123'],
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        DB::enableQueryLog();

        FieldResolverService::resolve(
            $this->company,
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queryCount, "FieldResolverService should use at most 3 queries, used {$queryCount}");
    }

    // ─── Cross-tenant write prevention ────────────────────

    public function test_cross_tenant_write_prevention(): void
    {
        $otherCompany = Company::create(['name' => 'Other Co', 'slug' => 'other-co']);

        // No activations for $otherCompany — write should be silently ignored
        FieldWriteService::upsert(
            $otherCompany,
            ['siret' => 'STOLEN-DATA'],
            FieldDefinition::SCOPE_COMPANY,
            $otherCompany->id,
        );

        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $this->assertDatabaseMissing('field_values', [
            'field_definition_id' => $siretDef->id,
            'model_type' => 'company',
            'model_id' => $otherCompany->id,
        ]);
    }

    public function test_company_scope_requires_company_id(): void
    {
        // Calling with null companyId on company scope should be no-op
        FieldWriteService::upsert(
            $this->company,
            ['siret' => 'NO-TENANT'],
            FieldDefinition::SCOPE_COMPANY,
            null, // Missing company ID
        );

        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $valCount = FieldValue::where('field_definition_id', $siretDef->id)
            ->where('model_type', 'company')
            ->where('model_id', $this->company->id)
            ->count();

        $this->assertEquals(0, $valCount);
    }

    // ─── Platform user dynamic fields ─────────────────────

    public function test_platform_user_update_saves_dynamic_fields(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/platform-users/{$this->platformAdmin->id}", [
                'name' => $this->platformAdmin->name,
                'dynamic_fields' => [
                    'internal_note' => 'This is a test note.',
                ],
            ]);

        $response->assertOk();

        $noteDef = FieldDefinition::where('code', 'internal_note')->first();
        $this->assertDatabaseHas('field_values', [
            'field_definition_id' => $noteDef->id,
            'model_type' => 'platform_user',
            'model_id' => $this->platformAdmin->id,
        ]);
    }

    // ─── Dynamic fields in read model ─────────────────────

    public function test_company_show_returns_dynamic_fields(): void
    {
        FieldWriteService::upsert(
            $this->company,
            ['siret' => '12345678901234'],
            FieldDefinition::SCOPE_COMPANY,
            $this->company->id,
        );

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/company');

        $response->assertOk()
            ->assertJsonStructure([
                'base_fields' => ['id', 'name', 'slug', 'status'],
                'dynamic_fields',
            ]);

        $dynamicFields = $response->json('dynamic_fields');
        $siret = collect($dynamicFields)->firstWhere('code', 'siret');
        $this->assertEquals('12345678901234', $siret['value']);
    }
}
