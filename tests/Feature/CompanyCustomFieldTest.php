<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;
    private Jobdomain $jobdomain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->jobdomain = Jobdomain::create([
            'key' => 'test_domain',
            'label' => 'Test Domain',
            'allow_custom_fields' => false,
        ]);

        $this->company->jobdomains()->sync([$this->jobdomain->id]);
    }

    private function act()
    {
        return $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── 1) Cannot create when jobdomain disallows ───────────

    public function test_company_cannot_create_custom_field_when_jobdomain_disallows(): void
    {
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'custom_one',
                'label' => 'Custom One',
                'scope' => 'company_user',
                'type' => 'string',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Your industry profile does not allow custom field creation.');
    }

    // ─── 2) Can create when jobdomain allows ────────────────

    public function test_company_can_create_custom_field_when_jobdomain_allows(): void
    {
        $this->jobdomain->update(['allow_custom_fields' => true]);

        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'custom_one',
                'label' => 'Custom One',
                'scope' => 'company_user',
                'type' => 'string',
            ]);

        $response->assertOk()
            ->assertJsonPath('field_definition.code', 'custom_one')
            ->assertJsonPath('field_definition.company_id', $this->company->id);

        // Verify auto-activation
        $this->assertDatabaseHas('field_activations', [
            'company_id' => $this->company->id,
            'field_definition_id' => $response->json('field_definition.id'),
            'enabled' => true,
        ]);
    }

    // ─── 3) Same code allowed in different companies ────────

    public function test_custom_field_unique_per_company(): void
    {
        $this->jobdomain->update(['allow_custom_fields' => true]);

        // Create in company A
        $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'shared_code',
                'label' => 'From Co A',
                'scope' => 'company',
                'type' => 'string',
            ])
            ->assertOk();

        // Create company B
        $ownerB = User::factory()->create();
        $companyB = Company::create(['name' => 'Co B', 'slug' => 'co-b']);
        $companyB->memberships()->create(['user_id' => $ownerB->id, 'role' => 'owner']);
        $companyB->jobdomains()->sync([$this->jobdomain->id]);

        // Same code in company B — should succeed
        $response = $this->actingAs($ownerB)
            ->withHeaders(['X-Company-Id' => $companyB->id])
            ->postJson('/api/company/field-definitions', [
                'code' => 'shared_code',
                'label' => 'From Co B',
                'scope' => 'company',
                'type' => 'string',
            ]);

        $response->assertOk()
            ->assertJsonPath('field_definition.company_id', $companyB->id);

        // Duplicate within same company — should fail
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'shared_code',
                'label' => 'Duplicate',
                'scope' => 'company',
                'type' => 'string',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'A custom field with this code already exists.');
    }

    // ─── 4) Cannot access other company's custom field ──────

    public function test_company_cannot_access_other_company_custom_field(): void
    {
        $this->jobdomain->update(['allow_custom_fields' => true]);

        // Create field in company A
        $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'secret',
                'label' => 'Secret',
                'scope' => 'company',
                'type' => 'string',
            ])
            ->assertOk();

        $fieldId = FieldDefinition::where('company_id', $this->company->id)
            ->where('code', 'secret')
            ->first()
            ->id;

        // Company B tries to update it
        $ownerB = User::factory()->create();
        $companyB = Company::create(['name' => 'Co B', 'slug' => 'co-b2']);
        $companyB->memberships()->create(['user_id' => $ownerB->id, 'role' => 'owner']);
        $companyB->jobdomains()->sync([$this->jobdomain->id]);

        $response = $this->actingAs($ownerB)
            ->withHeaders(['X-Company-Id' => $companyB->id])
            ->putJson("/api/company/field-definitions/{$fieldId}", [
                'label' => 'Hacked',
            ]);

        $response->assertStatus(404);
    }

    // ─── 5) Cannot delete if used ───────────────────────────

    public function test_cannot_delete_custom_field_if_used(): void
    {
        $this->jobdomain->update(['allow_custom_fields' => true]);

        $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'deletable',
                'label' => 'Deletable',
                'scope' => 'company_user',
                'type' => 'string',
            ])
            ->assertOk();

        $field = FieldDefinition::where('company_id', $this->company->id)
            ->where('code', 'deletable')
            ->first();

        // Add a value for this field
        FieldValue::create([
            'field_definition_id' => $field->id,
            'model_type' => 'user',
            'model_id' => $this->owner->id,
            'value' => 'test value',
        ]);

        // Try to delete — should fail
        $response = $this->act()
            ->deleteJson("/api/company/field-definitions/{$field->id}");

        $response->assertStatus(422);

        $this->assertStringContainsString('Cannot delete', $response->json('message'));

        // Without value — should succeed
        FieldValue::where('field_definition_id', $field->id)->delete();

        $this->act()
            ->deleteJson("/api/company/field-definitions/{$field->id}")
            ->assertOk();
    }

    // ─── 6) Custom field scope restricted ───────────────────

    public function test_custom_field_scope_restricted(): void
    {
        $this->jobdomain->update(['allow_custom_fields' => true]);

        // platform_user scope should be rejected
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'bad_scope',
                'label' => 'Bad',
                'scope' => 'platform_user',
                'type' => 'string',
            ]);

        $response->assertStatus(422);
    }

    // ─── 7) Max custom fields limit ─────────────────────────

    public function test_max_custom_fields_limit(): void
    {
        $this->jobdomain->update(['allow_custom_fields' => true]);

        // Create 20 custom fields
        for ($i = 1; $i <= 20; $i++) {
            $this->act()
                ->postJson('/api/company/field-definitions', [
                    'code' => "limit_field_{$i}",
                    'label' => "Limit Field {$i}",
                    'scope' => 'company_user',
                    'type' => 'string',
                ])
                ->assertOk();
        }

        // 21st should fail
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'limit_field_21',
                'label' => 'Limit Field 21',
                'scope' => 'company_user',
                'type' => 'string',
            ]);

        $response->assertStatus(422);

        $this->assertStringContainsString('Maximum number of custom fields reached', $response->json('message'));
    }
}
