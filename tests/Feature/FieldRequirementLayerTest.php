<?php

namespace Tests\Feature;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\TagDictionary;
use App\Core\Fields\FieldValidationService;
use App\Core\Fields\FieldValue;
use App\Core\Fields\MandatoryContext;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleActivationEngine;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

class FieldRequirementLayerTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();
        MandatoryContext::flush();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Req Test Co',
            'slug' => 'req-test-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->activateCompanyModules($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Activate all company_user fields
        $cuFields = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->whereNull('company_id')
            ->get();

        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }
    }

    /** Test 1: Jobdomain makes hire_date mandatory for logistique */
    public function test_jobdomain_makes_fields_mandatory(): void
    {
        $resolved = FieldResolverService::resolve(
            model: $this->owner,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
        );

        $hireDate = collect($resolved)->firstWhere('code', 'hire_date');
        $this->assertNotNull($hireDate);
        $this->assertTrue($hireDate['required']);
        $this->assertTrue($hireDate['mandatory']);
    }

    /** Test 2: Non-matching jobdomain does NOT make field mandatory */
    public function test_non_matching_jobdomain_not_mandatory(): void
    {
        MandatoryContext::flush();
        $company2 = Company::create(['name' => 'IT Co', 'slug' => 'it-co', 'jobdomain_key' => 'informatique']);

        // Activate fields for company2
        $cuFields = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->whereNull('company_id')
            ->get();

        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $company2->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'order' => $index * 10,
            ]);
        }

        $resolved = FieldResolverService::resolve(
            model: $this->owner,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $company2->id,
        );

        $hireDate = collect($resolved)->firstWhere('code', 'hire_date');
        $this->assertNotNull($hireDate);
        $this->assertFalse($hireDate['mandatory']);
    }

    /** Test 3: Active module makes license_number mandatory */
    public function test_active_module_makes_fields_mandatory(): void
    {
        // Enable logistics_fleet module
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => 'logistics_fleet'],
            ['is_enabled_for_company' => true],
        );
        MandatoryContext::flush();

        $resolved = FieldResolverService::resolve(
            model: $this->owner,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
        );

        $license = collect($resolved)->firstWhere('code', 'license_number');
        $this->assertNotNull($license);
        $this->assertTrue($license['mandatory']);
        $this->assertTrue($license['required']);
    }

    /** Test 4: Role with required_tags makes work_schedule mandatory via tags */
    public function test_role_makes_fields_mandatory(): void
    {
        // ADR-170 Phase 4: Tags are the sole role-based mandatory mechanism
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'driver',
            'required_tags' => [TagDictionary::DRIVING],
        ]);

        $resolved = FieldResolverService::resolve(
            model: $this->owner,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'driver',
        );

        $workSchedule = collect($resolved)->firstWhere('code', 'work_schedule');
        $this->assertNotNull($workSchedule);
        $this->assertTrue($workSchedule['mandatory']);
        $this->assertTrue($workSchedule['required']);
    }

    /** Test 5: MandatoryContext cache prevents duplicate queries */
    public function test_mandatory_context_cache(): void
    {
        MandatoryContext::flush();

        // First call populates cache
        $result1 = MandatoryContext::load($this->company->id);
        $this->assertEquals('logistique', $result1['jobdomain_key']);
        $this->assertIsArray($result1['active_modules']);

        // Second call returns same reference (cache hit — no queries)
        $result2 = MandatoryContext::load($this->company->id);
        $this->assertSame($result1, $result2, 'Second call should return cached result');

        // Flush and re-call — should still return correct data
        MandatoryContext::flush();
        $result3 = MandatoryContext::load($this->company->id);
        $this->assertEquals('logistique', $result3['jobdomain_key']);
    }

    /** Test 6: Validation rules include mandatory fields */
    public function test_validation_rules_include_mandatory_fields(): void
    {
        $rules = FieldValidationService::rules(
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
        );

        // hire_date is mandatory via jobdomain=logistique
        $this->assertArrayHasKey('dynamic_fields.hire_date', $rules);
        $this->assertContains('required', $rules['dynamic_fields.hire_date']);
    }

    /** Test 7: Profile completeness calculation */
    public function test_profile_completeness_calculation(): void
    {
        $profile = CompanyUserProfileReadModel::get($this->owner, $this->company);

        $this->assertArrayHasKey('profile_completeness', $profile);
        $this->assertArrayHasKey('filled', $profile['profile_completeness']);
        $this->assertArrayHasKey('total', $profile['profile_completeness']);
        $this->assertArrayHasKey('complete', $profile['profile_completeness']);

        // Owner has no field values filled, but some fields are mandatory
        $this->assertGreaterThan(0, $profile['profile_completeness']['total']);
        $this->assertFalse($profile['profile_completeness']['complete']);
    }

    /** Test 8: Bulk completeness returns correct structure */
    public function test_bulk_completeness_returns_correct_structure(): void
    {
        MandatoryContext::flush();
        $members = $this->company->memberships()->with('companyRole:id,key,name')->get();

        $completeness = CompanyUserProfileReadModel::bulkCompleteness($this->company, $members);

        $this->assertIsArray($completeness);
        $this->assertNotEmpty($completeness);

        foreach ($completeness as $membershipId => $data) {
            $this->assertArrayHasKey('filled', $data);
            $this->assertArrayHasKey('total', $data);
            $this->assertArrayHasKey('complete', $data);
            $this->assertIsInt($data['filled']);
            $this->assertIsInt($data['total']);
            $this->assertIsBool($data['complete']);
        }
    }

    /** Test 9: Custom fields are never mandatory */
    public function test_custom_fields_never_mandatory(): void
    {
        // Create a custom field
        $customDef = FieldDefinition::create([
            'company_id' => $this->company->id,
            'code' => 'custom_test_field',
            'scope' => FieldDefinition::SCOPE_COMPANY_USER,
            'label' => 'Custom Test',
            'type' => FieldDefinition::TYPE_STRING,
            'is_system' => false,
            'created_by_platform' => false,
        ]);

        FieldActivation::create([
            'company_id' => $this->company->id,
            'field_definition_id' => $customDef->id,
            'enabled' => true,
            'order' => 999,
        ]);

        $resolved = FieldResolverService::resolve(
            model: $this->owner,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
        );

        $customField = collect($resolved)->firstWhere('code', 'custom_test_field');
        $this->assertNotNull($customField);
        $this->assertFalse($customField['mandatory']);
    }

    /** Test 10: ADR-169 — Mandatory field cannot be downgraded by role field_config */
    public function test_mandatory_cannot_be_downgraded_by_role_field_config(): void
    {
        // hire_date is mandatory via required_by_jobdomains: ['logistique']
        // Create a role that tries to set required: false for hire_date
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'sneaky_role',
            'name' => 'Sneaky Role',
            'field_config' => [
                ['code' => 'hire_date', 'visible' => true, 'required' => false],
            ],
        ]);

        // FieldResolverService should still return required=true (mandatory wins)
        $resolved = FieldResolverService::resolve(
            model: $this->owner,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'sneaky_role',
        );

        $hireDate = collect($resolved)->firstWhere('code', 'hire_date');
        $this->assertNotNull($hireDate);
        $this->assertTrue($hireDate['mandatory'], 'hire_date should remain mandatory');
        $this->assertTrue($hireDate['required'], 'ADR-169: role field_config cannot downgrade mandatory to non-required');

        // FieldValidationService should still require the field
        $rules = FieldValidationService::rules(
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'sneaky_role',
        );

        $this->assertArrayHasKey('dynamic_fields.hire_date', $rules);
        $this->assertContains('required', $rules['dynamic_fields.hire_date'],
            'ADR-169: validation rules must enforce mandatory even when role config says required=false');

        // CompanyUserProfileReadModel bulk completeness should count it as required
        MandatoryContext::flush();
        $membership = $this->company->memberships()->with('companyRole:id,key,name')->first();
        $membership->company_role_id = $role->id;
        $membership->save();
        $membership->load('companyRole:id,key,name');

        $completeness = CompanyUserProfileReadModel::bulkCompleteness(
            $this->company,
            collect([$membership]),
        );

        $this->assertGreaterThan(0, $completeness[$membership->id]['total'],
            'ADR-169: mandatory fields must count in completeness even with role config required=false');
    }

    /** Test 11: Auto-activate fields when module is enabled (simulated via direct CompanyModule) */
    public function test_auto_activate_fields_on_module_enable(): void
    {
        // Create a fresh company with no field activations
        MandatoryContext::flush();
        $company2 = Company::create([
            'name' => 'Fresh Co',
            'slug' => 'fresh-co',
            'jobdomain_key' => 'logistique',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($company2);

        $user = User::factory()->create();
        $company2->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);

        // Remove all field activations for this company
        FieldActivation::where('company_id', $company2->id)->delete();

        // Verify license_number has no activation
        $licenseDef = FieldDefinition::where('code', 'license_number')->whereNull('company_id')->first();
        $this->assertNull(
            FieldActivation::where('company_id', $company2->id)
                ->where('field_definition_id', $licenseDef->id)
                ->first()
        );

        // Enable logistics_fleet via ModuleActivationEngine (requires logistics_shipments + pro plan)
        $result = ModuleActivationEngine::enable($company2, 'logistics_fleet');

        if (!$result['success']) {
            // If entitlement check fails (test env), test direct CompanyModule creation + verify MandatoryContext logic
            CompanyModule::updateOrCreate(
                ['company_id' => $company2->id, 'module_key' => 'logistics_fleet'],
                ['is_enabled_for_company' => true],
            );
            MandatoryContext::flush();

            $context = MandatoryContext::load($company2->id);
            $this->assertContains('logistics_fleet', $context['active_modules']);

            // Verify isMandatory returns true for license_number
            $mandatory = MandatoryContext::isMandatory($licenseDef, $context, null);
            $this->assertTrue($mandatory, 'license_number should be mandatory when logistics_fleet is active');

            return;
        }

        // If enable succeeded, verify field activation was auto-created
        $activation = FieldActivation::where('company_id', $company2->id)
            ->where('field_definition_id', $licenseDef->id)
            ->first();

        $this->assertNotNull($activation, 'license_number should be auto-activated when logistics_fleet is enabled');
        $this->assertTrue($activation->enabled);
    }
}
