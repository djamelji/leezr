<?php

namespace Tests\Feature;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobdomainFieldPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $companyOwner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SystemSeeder::class);

        $this->companyOwner = User::factory()->create();
        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $this->company->memberships()->create([
            'user_id' => $this->companyOwner->id,
            'role' => 'owner',
        ]);
    }

    public function test_assign_jobdomain_activates_default_fields(): void
    {
        $this->assertEquals(0, FieldActivation::where('company_id', $this->company->id)->count());

        JobdomainGate::assignToCompany($this->company, 'logistique');

        $activations = FieldActivation::where('company_id', $this->company->id)->get();
        $this->assertGreaterThanOrEqual(5, $activations->count());

        $defaultFields = JobdomainRegistry::get('logistique')['default_fields'];

        $activatedDefIds = $activations->pluck('field_definition_id')->toArray();
        $activatedDefs = FieldDefinition::whereIn('id', $activatedDefIds)->pluck('code')->toArray();

        foreach ($defaultFields as $fieldConfig) {
            $code = $fieldConfig['code'];
            $def = FieldDefinition::where('code', $code)->first();
            if ($def && in_array($def->scope, [FieldDefinition::SCOPE_COMPANY, FieldDefinition::SCOPE_COMPANY_USER])) {
                $this->assertContains($code, $activatedDefs, "Field '{$code}' should be activated");
            }
        }
    }

    public function test_field_presets_are_enabled_by_default(): void
    {
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $activations = FieldActivation::where('company_id', $this->company->id)->get();

        foreach ($activations as $activation) {
            $this->assertTrue($activation->enabled, "Activation for def {$activation->field_definition_id} should be enabled");
        }
    }

    public function test_field_presets_respect_structured_order(): void
    {
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $activation = FieldActivation::where('company_id', $this->company->id)
            ->where('field_definition_id', $siretDef->id)
            ->first();

        $this->assertNotNull($activation);
        // siret has order: 0 in the structured preset
        $this->assertEquals(0, $activation->order);
    }

    public function test_field_presets_respect_structured_required(): void
    {
        JobdomainGate::assignToCompany($this->company, 'logistique');

        // siret has required: true in the structured preset
        $siretDef = FieldDefinition::where('code', 'siret')->first();
        $activation = FieldActivation::where('company_id', $this->company->id)
            ->where('field_definition_id', $siretDef->id)
            ->first();

        $this->assertTrue($activation->required_override);

        // phone has required: false in the structured preset
        $phoneDef = FieldDefinition::where('code', 'phone')->first();
        $phoneActivation = FieldActivation::where('company_id', $this->company->id)
            ->where('field_definition_id', $phoneDef->id)
            ->first();

        $this->assertFalse($phoneActivation->required_override);
    }

    public function test_field_presets_are_idempotent(): void
    {
        JobdomainGate::assignToCompany($this->company, 'logistique');
        $countAfterFirst = FieldActivation::where('company_id', $this->company->id)->count();

        JobdomainGate::assignToCompany($this->company, 'logistique');
        $countAfterSecond = FieldActivation::where('company_id', $this->company->id)->count();

        $this->assertEquals($countAfterFirst, $countAfterSecond);
    }

    public function test_platform_user_fields_are_not_activated(): void
    {
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $internalNoteDef = FieldDefinition::where('code', 'internal_note')->first();

        $activation = FieldActivation::where('company_id', $this->company->id)
            ->where('field_definition_id', $internalNoteDef->id)
            ->first();

        $this->assertNull($activation);
    }

    public function test_default_fields_helper_returns_structured_array(): void
    {
        $fields = JobdomainGate::defaultFieldsFor('logistique');

        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);

        $codes = array_column($fields, 'code');
        $this->assertContains('siret', $codes);
        $this->assertContains('phone', $codes);

        // Verify structure
        $first = $fields[0];
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('required', $first);
        $this->assertArrayHasKey('order', $first);
    }

    public function test_default_fields_helper_returns_empty_for_unknown(): void
    {
        $fields = JobdomainGate::defaultFieldsFor('nonexistent');

        $this->assertIsArray($fields);
        $this->assertEmpty($fields);
    }
}
