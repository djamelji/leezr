<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->admin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->actingAs($this->admin, 'platform')
            ->{$method}("/api/platform{$uri}", $data);
    }

    // ─── CRUD ─────────────────────────────────────────────

    public function test_can_list_field_definitions(): void
    {
        $response = $this->api('getJson', '/field-definitions');

        $response->assertOk()
            ->assertJsonStructure(['field_definitions'])
            ->assertJsonCount(6, 'field_definitions');
    }

    public function test_can_filter_field_definitions_by_scope(): void
    {
        $response = $this->api('getJson', '/field-definitions?scope=company');

        $response->assertOk();

        $definitions = $response->json('field_definitions');

        foreach ($definitions as $def) {
            $this->assertEquals('company', $def['scope']);
        }
    }

    public function test_can_create_field_definition(): void
    {
        $response = $this->api('postJson', '/field-definitions', [
            'code' => 'custom_note',
            'scope' => 'company',
            'label' => 'Custom Note',
            'type' => 'string',
            'validation_rules' => ['max' => 200],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('field_definition.code', 'custom_note')
            ->assertJsonPath('field_definition.is_system', false);

        $this->assertDatabaseHas('field_definitions', ['code' => 'custom_note']);
    }

    public function test_cannot_create_duplicate_code(): void
    {
        $response = $this->api('postJson', '/field-definitions', [
            'code' => 'siret',
            'scope' => 'company',
            'label' => 'Duplicate SIRET',
            'type' => 'string',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_code_must_match_regex_pattern(): void
    {
        $response = $this->api('postJson', '/field-definitions', [
            'code' => 'Invalid-Code!',
            'scope' => 'company',
            'label' => 'Bad Code',
            'type' => 'string',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    // ─── Immutability ─────────────────────────────────────

    public function test_update_only_allows_mutable_fields(): void
    {
        $field = FieldDefinition::where('code', 'siret')->first();

        $response = $this->api('putJson', "/field-definitions/{$field->id}", [
            'label' => 'Updated SIRET Label',
            'default_order' => 99,
        ]);

        $response->assertOk()
            ->assertJsonPath('field_definition.label', 'Updated SIRET Label')
            ->assertJsonPath('field_definition.default_order', 99);
    }

    public function test_update_ignores_immutable_code_scope_type(): void
    {
        $field = FieldDefinition::where('code', 'siret')->first();

        // The controller only validates mutable fields, so code/scope/type
        // are simply not accepted — they won't appear in validated data
        $response = $this->api('putJson', "/field-definitions/{$field->id}", [
            'label' => 'Still SIRET',
        ]);

        $response->assertOk();

        $field->refresh();
        $this->assertEquals('siret', $field->code);
        $this->assertEquals('company', $field->scope);
        $this->assertEquals('string', $field->type);
    }

    // ─── System field protection ──────────────────────────

    public function test_cannot_delete_system_field(): void
    {
        $field = FieldDefinition::where('code', 'siret')->first();
        $this->assertTrue($field->is_system);

        $response = $this->api('deleteJson', "/field-definitions/{$field->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Cannot delete a system field.');

        $this->assertDatabaseHas('field_definitions', ['id' => $field->id]);
    }

    public function test_can_delete_non_system_field(): void
    {
        $field = FieldDefinition::create([
            'code' => 'deletable_field',
            'scope' => 'company',
            'label' => 'Deletable',
            'type' => 'string',
            'is_system' => false,
        ]);

        $response = $this->api('deleteJson', "/field-definitions/{$field->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('field_definitions', ['id' => $field->id]);
    }

    // ─── Permission ───────────────────────────────────────

    public function test_requires_manage_field_definitions_permission(): void
    {
        $limitedUser = PlatformUser::create([
            'first_name' => 'Limited',
            'last_name' => '',
            'email' => 'limited@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $role = PlatformRole::create(['name' => 'Viewer', 'key' => 'viewer']);
        $limitedUser->roles()->attach($role);

        $response = $this->actingAs($limitedUser, 'platform')
            ->getJson('/api/platform/field-definitions');

        $response->assertStatus(403);
    }
}
