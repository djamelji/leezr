<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldActivationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;
    private User $companyOwner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        // Platform admin
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        // Company context
        $this->companyOwner = User::factory()->create();
        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $this->company->memberships()->create([
            'user_id' => $this->companyOwner->id,
            'role' => 'owner',
        ]);
    }

    // ─── Platform activations ─────────────────────────────

    public function test_platform_activation_accepts_platform_user_scope(): void
    {
        $field = FieldDefinition::where('code', 'internal_note')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/field-activations', [
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => 10,
            ]);

        $response->assertOk()
            ->assertJsonPath('field_activation.field_definition_id', $field->id)
            ->assertJsonPath('field_activation.enabled', true);
    }

    public function test_platform_activation_rejects_company_scope(): void
    {
        $field = FieldDefinition::where('code', 'siret')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/field-activations', [
                'field_definition_id' => $field->id,
                'enabled' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This field definition is not platform_user scope.');
    }

    // ─── Company activations ──────────────────────────────

    public function test_company_activation_accepts_company_scope(): void
    {
        $field = FieldDefinition::where('code', 'siret')->first();

        $response = $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/field-activations', [
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('field_activation.field_definition_id', $field->id);
    }

    public function test_company_activation_accepts_company_user_scope(): void
    {
        $field = FieldDefinition::where('code', 'phone')->first();

        $response = $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/field-activations', [
                'field_definition_id' => $field->id,
                'enabled' => true,
            ]);

        $response->assertOk();
    }

    public function test_company_activation_rejects_platform_user_scope(): void
    {
        $field = FieldDefinition::where('code', 'internal_note')->first();

        $response = $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/field-activations', [
                'field_definition_id' => $field->id,
                'enabled' => true,
            ]);

        $response->assertStatus(422);
    }

    // ─── Upsert idempotency ──────────────────────────────

    public function test_upsert_is_idempotent(): void
    {
        $field = FieldDefinition::where('code', 'siret')->first();

        $payload = [
            'field_definition_id' => $field->id,
            'enabled' => true,
            'order' => 10,
        ];

        // First call
        $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/field-activations', $payload)
            ->assertOk();

        // Second call — same data
        $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/field-activations', $payload)
            ->assertOk();

        // Only one activation should exist
        $count = FieldActivation::where('company_id', $this->company->id)
            ->where('field_definition_id', $field->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    // ─── Max activations guard ────────────────────────────

    public function test_max_activations_guard(): void
    {
        // Create 50 field definitions in company scope
        for ($i = 1; $i <= 50; $i++) {
            $def = FieldDefinition::create([
                'code' => "test_field_{$i}",
                'scope' => 'company',
                'label' => "Test Field {$i}",
                'type' => 'string',
                'is_system' => false,
            ]);

            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $def->id,
                'enabled' => true,
                'order' => $i,
            ]);
        }

        // 51st should fail
        $newDef = FieldDefinition::create([
            'code' => 'test_field_51',
            'scope' => 'company',
            'label' => 'Test Field 51',
            'type' => 'string',
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/company/field-activations', [
                'field_definition_id' => $newDef->id,
                'enabled' => true,
            ]);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'Maximum number of active fields reached',
            $response->json('message'),
        );
    }

    // ─── Index returns activations + available ────────────

    public function test_company_index_returns_activations_and_available(): void
    {
        $response = $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/company/field-activations');

        $response->assertOk()
            ->assertJsonStructure([
                'field_activations',
                'available_definitions',
            ]);
    }

    // ─── Index returns used_count per activation ────────

    public function test_company_index_returns_used_count(): void
    {
        $phoneDef = FieldDefinition::where('code', 'phone')->first();

        // Activate the phone field
        FieldActivation::create([
            'company_id' => $this->company->id,
            'field_definition_id' => $phoneDef->id,
            'enabled' => true,
            'order' => 0,
        ]);

        // Create a field value for the owner
        FieldValue::create([
            'field_definition_id' => $phoneDef->id,
            'model_type' => 'user',
            'model_id' => $this->companyOwner->id,
            'value' => '+33612345678',
        ]);

        $response = $this->actingAs($this->companyOwner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/company/field-activations');

        $response->assertOk();

        $activations = collect($response->json('field_activations'));
        $phoneActivation = $activations->firstWhere('field_definition_id', $phoneDef->id);

        $this->assertNotNull($phoneActivation);
        $this->assertEquals(1, $phoneActivation['used_count']);
    }
}
