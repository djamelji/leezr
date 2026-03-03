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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-168 + ADR-169: Field Categories.
 *
 * Tests:
 * - Self-edit (profile) sees/writes all categories (ADR-169)
 * - Admin-edit (members) sees all categories
 * - FieldResolverService filters by category when requested
 * - FieldWriteService enforces category guard when category param provided
 * - Custom fields default to base category
 */
class FieldCategoryTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules, SetsUpCompanyRbac;

    private User $owner;
    private User $member;
    private Company $company;
    private $ownerMembership;
    private $memberMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create(['name' => 'Cat Co', 'slug' => 'cat-co', 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        // Create a basic member role (non-admin)
        $memberRole = \App\Company\RBAC\CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'employee',
            'name' => 'Employee',
        ]);

        $this->ownerMembership = $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $memberRole->id,
        ]);

        // Activate all company_user fields
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

    private function actAsOwner()
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function actAsMember()
    {
        return $this->actingAs($this->member)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── 1) Self-profile show returns all categories (ADR-169) ──────

    public function test_self_profile_show_returns_all_categories(): void
    {
        // ADR-169: self-profile now shows all field categories (not just base)
        $response = $this->actAsMember()->getJson('/api/profile');

        $response->assertOk();

        $categories = collect($response->json('dynamic_fields'))->pluck('category')->unique()->toArray();

        $this->assertContains('base', $categories);
        $this->assertContains('hr', $categories);
        $this->assertContains('domain', $categories);

        // Spot-check specific fields from each category
        $codes = collect($response->json('dynamic_fields'))->pluck('code')->toArray();
        $this->assertContains('phone', $codes);
        $this->assertContains('social_security_number', $codes);
        $this->assertContains('license_number', $codes);
    }

    // ─── 2) Self-profile update writes all categories (ADR-169) ─────

    public function test_self_profile_update_writes_all_categories(): void
    {
        // ADR-169: self-profile can now write all field categories (role field_config controls visibility)
        $this->actAsMember()->putJson('/api/profile', [
            'first_name' => $this->member->first_name,
            'last_name' => $this->member->last_name,
            'dynamic_fields' => [
                'phone' => '+33612345678',
                'social_security_number' => '1234567890',
            ],
        ])->assertOk();

        // Phone (base) should be written
        $phoneDef = FieldDefinition::whereNull('company_id')->where('code', 'phone')->first();
        $phoneValue = FieldValue::where('field_definition_id', $phoneDef->id)->where('model_id', $this->member->id)->first();
        $this->assertNotNull($phoneValue);
        $this->assertEquals('+33612345678', $phoneValue->value);

        // SSN (hr) should also be written now
        $ssn = FieldDefinition::whereNull('company_id')->where('code', 'social_security_number')->first();
        $ssnValue = FieldValue::where('field_definition_id', $ssn->id)->where('model_id', $this->member->id)->first();
        $this->assertNotNull($ssnValue);
        $this->assertEquals('1234567890', $ssnValue->value);
    }

    // ─── 3) Admin show returns all categories ───────────────

    public function test_admin_show_returns_all_categories(): void
    {
        $response = $this->actAsOwner()
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $response->assertOk();

        $categories = collect($response->json('dynamic_fields'))->pluck('category')->unique()->toArray();

        $this->assertContains('base', $categories);
        $this->assertContains('hr', $categories);
        $this->assertContains('domain', $categories);
    }

    // ─── 4) Admin update writes all categories ──────────────

    public function test_admin_update_writes_all_categories(): void
    {
        $this->actAsOwner()
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'first_name' => $this->member->first_name,
                'last_name' => $this->member->last_name,
                'dynamic_fields' => [
                    'phone' => '+33600000001',
                    'social_security_number' => '999888777',
                    'license_number' => 'DRV-001',
                ],
            ])->assertOk();

        // All three should be written
        $this->assertDatabaseHas('field_values', [
            'model_id' => $this->member->id,
            'field_definition_id' => FieldDefinition::whereNull('company_id')->where('code', 'phone')->first()->id,
        ]);
        $ssnDef = FieldDefinition::whereNull('company_id')->where('code', 'social_security_number')->first();
        $ssnValue = FieldValue::where('field_definition_id', $ssnDef->id)->where('model_id', $this->member->id)->first();
        $this->assertNotNull($ssnValue);
        $this->assertEquals('999888777', $ssnValue->value);

        $licenseDef = FieldDefinition::whereNull('company_id')->where('code', 'license_number')->first();
        $licenseValue = FieldValue::where('field_definition_id', $licenseDef->id)->where('model_id', $this->member->id)->first();
        $this->assertNotNull($licenseValue);
        $this->assertEquals('DRV-001', $licenseValue->value);
    }

    // ─── 5) Resolver filters by category when provided ──────

    public function test_resolver_filters_by_category_when_provided(): void
    {
        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            category: FieldDefinition::CATEGORY_BASE,
        );

        $categories = collect($fields)->pluck('category')->unique()->toArray();

        $this->assertEquals(['base'], $categories);
    }

    // ─── 6) Resolver returns all when no category ───────────

    public function test_resolver_returns_all_when_no_category(): void
    {
        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
        );

        $categories = collect($fields)->pluck('category')->unique()->sort()->values()->toArray();

        $this->assertContains('base', $categories);
        $this->assertContains('hr', $categories);
        $this->assertContains('domain', $categories);
    }

    // ─── 7) Resolver exposes category in output ─────────────

    public function test_resolver_exposes_category_in_output(): void
    {
        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
        );

        foreach ($fields as $field) {
            $this->assertArrayHasKey('category', $field);
            $this->assertContains($field['category'], FieldDefinition::CATEGORIES);
        }
    }

    // ─── 8) Custom fields default to base category ──────────

    public function test_custom_fields_default_to_base_category(): void
    {
        // Create a custom field with no category
        $customField = FieldDefinition::create([
            'company_id' => $this->company->id,
            'code' => 'custom_test',
            'scope' => FieldDefinition::SCOPE_COMPANY_USER,
            'label' => 'Custom Test',
            'type' => FieldDefinition::TYPE_STRING,
            'is_system' => false,
        ]);

        FieldActivation::create([
            'company_id' => $this->company->id,
            'field_definition_id' => $customField->id,
            'enabled' => true,
            'order' => 999,
        ]);

        // Resolver with category=base should include custom field
        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            category: FieldDefinition::CATEGORY_BASE,
        );

        $codes = collect($fields)->pluck('code')->toArray();
        $this->assertContains('custom_test', $codes);

        // Custom field should have category=base in output
        $customInResult = collect($fields)->firstWhere('code', 'custom_test');
        $this->assertEquals('base', $customInResult['category']);
    }

    // ─── 9) Write service ignores HR fields when category=base ──

    public function test_write_service_ignores_hr_fields_when_category_base(): void
    {
        FieldWriteService::upsert(
            model: $this->member,
            dynamicFields: [
                'phone' => '+33611111111',
                'social_security_number' => 'BLOCKED',
            ],
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            category: FieldDefinition::CATEGORY_BASE,
        );

        // Phone (base) should be written
        $phoneDef = FieldDefinition::whereNull('company_id')->where('code', 'phone')->first();
        $this->assertDatabaseHas('field_values', [
            'field_definition_id' => $phoneDef->id,
            'model_id' => $this->member->id,
        ]);

        // SSN (hr) should NOT be written
        $ssnDef = FieldDefinition::whereNull('company_id')->where('code', 'social_security_number')->first();
        $this->assertDatabaseMissing('field_values', [
            'field_definition_id' => $ssnDef->id,
            'model_id' => $this->member->id,
        ]);
    }

    // ─── 10) Self-profile writes submitted HR fields (ADR-169) ─

    public function test_self_profile_writes_submitted_hr_fields(): void
    {
        // ADR-169: self-profile now writes all submitted field categories
        $response = $this->actAsMember()->putJson('/api/profile', [
            'first_name' => $this->member->first_name,
            'last_name' => $this->member->last_name,
            'dynamic_fields' => [
                'iban' => 'FR7630006000011234567890189',
            ],
        ]);

        $response->assertOk();

        // IBAN (hr) should be written
        $ibanDef = FieldDefinition::whereNull('company_id')->where('code', 'iban')->first();
        $this->assertDatabaseHas('field_values', [
            'field_definition_id' => $ibanDef->id,
            'model_id' => $this->member->id,
        ]);
    }
}
