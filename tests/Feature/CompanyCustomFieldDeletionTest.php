<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCustomFieldDeletionTest extends TestCase
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
            'allow_custom_fields' => true,
        ]);

        $this->company->jobdomains()->sync([$this->jobdomain->id]);
    }

    private function act()
    {
        return $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function createCustomField(string $code = 'test_field', string $type = 'string', ?array $options = null): FieldDefinition
    {
        $payload = [
            'code' => $code,
            'label' => ucfirst(str_replace('_', ' ', $code)),
            'scope' => 'company_user',
            'type' => $type,
        ];

        if ($options !== null) {
            $payload['options'] = $options;
        }

        $this->act()->postJson('/api/company/field-definitions', $payload)->assertOk();

        return FieldDefinition::where('company_id', $this->company->id)
            ->where('code', $code)
            ->first();
    }

    // ─── 1) Custom field can be deleted even if used ──────

    public function test_custom_field_can_be_deleted_even_if_used(): void
    {
        $field = $this->createCustomField();

        FieldValue::create([
            'field_definition_id' => $field->id,
            'model_type' => 'user',
            'model_id' => $this->owner->id,
            'value' => 'some value',
        ]);

        $response = $this->act()
            ->deleteJson("/api/company/field-definitions/{$field->id}");

        $response->assertOk();
    }

    // ─── 2) Deletion removes field values ─────────────────

    public function test_deletion_removes_field_values(): void
    {
        $field = $this->createCustomField();

        $member = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $member->id, 'role' => 'user']);

        FieldValue::create([
            'field_definition_id' => $field->id,
            'model_type' => 'user',
            'model_id' => $this->owner->id,
            'value' => 'value1',
        ]);

        FieldValue::create([
            'field_definition_id' => $field->id,
            'model_type' => 'user',
            'model_id' => $member->id,
            'value' => 'value2',
        ]);

        $this->act()
            ->deleteJson("/api/company/field-definitions/{$field->id}")
            ->assertOk();

        $this->assertDatabaseMissing('field_values', ['field_definition_id' => $field->id]);
        $this->assertDatabaseMissing('field_activations', ['field_definition_id' => $field->id]);
        $this->assertDatabaseMissing('field_definitions', ['id' => $field->id]);
    }

    // ─── 3) System field cannot be deleted if used ────────

    public function test_system_field_cannot_be_deleted(): void
    {
        $systemField = FieldDefinition::where('is_system', true)->first();

        $response = $this->act()
            ->deleteJson("/api/company/field-definitions/{$systemField->id}");

        // System fields are scoped to company_id = null, so findOrFail with company_id filter → 404
        $response->assertStatus(404);
    }

    // ─── 4) Company cannot delete other company's field ───

    public function test_company_cannot_delete_other_company_custom_field(): void
    {
        $field = $this->createCustomField();

        $ownerB = User::factory()->create();
        $companyB = Company::create(['name' => 'Co B', 'slug' => 'co-b']);
        $companyB->memberships()->create(['user_id' => $ownerB->id, 'role' => 'owner']);
        $companyB->jobdomains()->sync([$this->jobdomain->id]);

        $response = $this->actingAs($ownerB)
            ->withHeaders(['X-Company-Id' => $companyB->id])
            ->deleteJson("/api/company/field-definitions/{$field->id}");

        $response->assertStatus(404);

        // Original field still exists
        $this->assertDatabaseHas('field_definitions', ['id' => $field->id]);
    }

    // ─── 5) Deletion returns deleted_values count ─────────

    public function test_deletion_returns_deleted_values_count(): void
    {
        $field = $this->createCustomField();

        // 3 values
        for ($i = 1; $i <= 3; $i++) {
            $user = User::factory()->create();
            $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'user']);

            FieldValue::create([
                'field_definition_id' => $field->id,
                'model_type' => 'user',
                'model_id' => $user->id,
                'value' => "val{$i}",
            ]);
        }

        $response = $this->act()
            ->deleteJson("/api/company/field-definitions/{$field->id}");

        $response->assertOk()
            ->assertJsonPath('deleted_values', 3);
    }

    // ─── 6) Select field requires options ─────────────────

    public function test_select_field_requires_options(): void
    {
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'bad_select',
                'label' => 'Bad Select',
                'scope' => 'company_user',
                'type' => 'select',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    // ─── 7) Select field requires at least one option ─────

    public function test_select_field_requires_at_least_one_option(): void
    {
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'empty_select',
                'label' => 'Empty Select',
                'scope' => 'company_user',
                'type' => 'select',
                'options' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('options');
    }

    // ─── 8) JSON field type is rejected ───────────────────

    public function test_json_field_type_is_rejected(): void
    {
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'bad_json',
                'label' => 'Bad JSON',
                'scope' => 'company_user',
                'type' => 'json',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}
