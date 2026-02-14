<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->user = User::factory()->create();
        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $this->company->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);

        // Activate company_user fields
        $cuFields = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();

        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }
    }

    private function actAs()
    {
        return $this->actingAs($this->user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── 1) Profile update with first_name / last_name ──

    public function test_profile_update_first_name_last_name(): void
    {
        $response = $this->actAs()
            ->putJson('/api/profile', [
                'first_name' => 'Jean',
                'last_name' => 'Dupont',
            ]);

        $response->assertOk()
            ->assertJsonPath('base_fields.first_name', 'Jean')
            ->assertJsonPath('base_fields.last_name', 'Dupont')
            ->assertJsonPath('base_fields.display_name', 'Jean Dupont');

        $this->user->refresh();
        $this->assertEquals('Jean', $this->user->first_name);
        $this->assertEquals('Dupont', $this->user->last_name);
    }

    // ─── 2) Profile update with dynamic fields ──────────

    public function test_profile_update_dynamic_fields(): void
    {
        $phone = FieldDefinition::where('code', 'phone')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->first();

        if (!$phone) {
            $this->markTestSkipped('No phone field defined for company_user scope');
        }

        $response = $this->actAs()
            ->putJson('/api/profile', [
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'dynamic_fields' => [
                    'phone' => '+33 6 12 34 56 78',
                ],
            ]);

        $response->assertOk();

        $phoneField = collect($response->json('dynamic_fields'))
            ->firstWhere('code', 'phone');

        $this->assertEquals('+33 6 12 34 56 78', $phoneField['value']);
    }
}
