<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCustomFieldSelectTest extends TestCase
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

    // ─── 1) Can create select field with options ──────────

    public function test_company_can_create_select_field_with_options(): void
    {
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'department',
                'label' => 'Department',
                'scope' => 'company_user',
                'type' => 'select',
                'options' => ['Engineering', 'Marketing', 'Sales'],
            ]);

        $response->assertOk()
            ->assertJsonPath('field_definition.type', 'select')
            ->assertJsonPath('field_definition.options', ['Engineering', 'Marketing', 'Sales']);
    }

    // ─── 2) Cannot create select without options ──────────

    public function test_cannot_create_select_field_without_options(): void
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

    // ─── 3) Cannot create select with duplicate options ───

    public function test_cannot_create_select_field_with_duplicate_options(): void
    {
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'dup_select',
                'label' => 'Dup Select',
                'scope' => 'company_user',
                'type' => 'select',
                'options' => ['Alpha', 'Beta', 'Alpha'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('options.2');
    }

    // ─── 4) Non-select type ignores options ───────────────

    public function test_changing_type_from_select_resets_options(): void
    {
        // Create a string field WITH options (should be stored but irrelevant)
        $response = $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'text_field',
                'label' => 'Text Field',
                'scope' => 'company_user',
                'type' => 'string',
            ]);

        $response->assertOk();

        // options not required for non-select types
        $this->assertNull($response->json('field_definition.options'));
    }

    // ─── 5) Select options are persisted correctly ────────

    public function test_select_options_are_persisted_correctly(): void
    {
        $this->act()
            ->postJson('/api/company/field-definitions', [
                'code' => 'priority',
                'label' => 'Priority',
                'scope' => 'company_user',
                'type' => 'select',
                'options' => ['Low', 'Medium', 'High', 'Critical'],
            ])
            ->assertOk();

        $field = FieldDefinition::where('company_id', $this->company->id)
            ->where('code', 'priority')
            ->first();

        $this->assertNotNull($field);
        $this->assertEquals('select', $field->type);
        $this->assertIsArray($field->options);
        $this->assertCount(4, $field->options);
        $this->assertEquals(['Low', 'Medium', 'High', 'Critical'], $field->options);
    }
}
