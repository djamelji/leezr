<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldConfigHealthCheck;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValidationService;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-164: Actor Profiles & Role-Scoped Fields.
 *
 * Tests the field_config override layer on CompanyRole:
 * - Backward compatibility (no roleKey / null field_config → all fields)
 * - Override semantics (not a whitelist — unlisted fields remain visible)
 * - visible/required/order/group overrides
 * - Unknown codes silently ignored
 * - Jobdomain seeding populates field_config
 * - FieldValidationService respects roleKey
 * - FieldConfigHealthCheck detects orphaned references
 */
class ActorProfileFieldResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        FieldDefinitionCatalog::sync();
        CompanyPermissionCatalog::sync();
        JobdomainRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Profile Co', 'slug' => 'profile-co', 'jobdomain_key' => 'logistique']);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Activate company_user fields (phone, job_title)
        $cuFields = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();
        foreach ($cuFields as $index => $field) {
            FieldActivation::create([
                'company_id' => $this->company->id,
                'field_definition_id' => $field->id,
                'enabled' => true,
                'required_override' => false,
                'order' => ($index + 1) * 10,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════
    // Backward compatibility
    // ═══════════════════════════════════════════════════════

    public function test_resolve_without_role_key_returns_all_fields(): void
    {
        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
        );

        $codes = array_column($fields, 'code');
        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes);
        $this->assertCount(20, $fields);
    }

    public function test_resolve_with_role_key_and_null_field_config_returns_all_fields(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'basic',
            'name' => 'Basic',
            'field_config' => null,
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'basic',
        );

        $codes = array_column($fields, 'code');
        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes);
    }

    public function test_resolve_with_nonexistent_role_returns_all_fields(): void
    {
        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'nonexistent_role',
        );

        $this->assertCount(20, $fields);
    }

    // ═══════════════════════════════════════════════════════
    // Override layer (NOT whitelist)
    // ═══════════════════════════════════════════════════════

    public function test_fields_not_in_config_remain_visible(): void
    {
        // field_config mentions only phone — job_title should still be visible
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'partial',
            'name' => 'Partial Config',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 5, 'group' => 'contact', 'scope' => 'company_user'],
            ],
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'partial',
        );

        $codes = array_column($fields, 'code');
        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes, 'Unlisted field must remain visible');
        $this->assertCount(20, $fields);
    }

    public function test_resolve_respects_visible_false(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'job_title', 'required' => false, 'visible' => false, 'order' => 1, 'scope' => 'company_user'],
            ],
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'driver',
        );

        $codes = array_column($fields, 'code');
        $this->assertContains('phone', $codes);
        $this->assertNotContains('job_title', $codes, 'visible=false must hide the field');
        $this->assertCount(19, $fields);
    }

    // ═══════════════════════════════════════════════════════
    // Override properties
    // ═══════════════════════════════════════════════════════

    public function test_resolve_overrides_required_from_field_config(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
            ],
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'manager',
        );

        $phoneField = collect($fields)->firstWhere('code', 'phone');
        $this->assertTrue($phoneField['required'], 'field_config required=true must override activation default');
    }

    public function test_resolve_overrides_order_from_field_config(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'reorder',
            'name' => 'Reorder',
            'field_config' => [
                ['code' => 'job_title', 'required' => false, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'phone', 'required' => false, 'visible' => true, 'order' => 99, 'scope' => 'company_user'],
            ],
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'reorder',
        );

        // job_title (order=0) must come before phone (order=99)
        $codes = array_column($fields, 'code');
        $jobTitlePos = array_search('job_title', $codes);
        $phonePos = array_search('phone', $codes);
        $this->assertSame(0, $jobTitlePos, 'job_title at order=0 should be first');
        $this->assertGreaterThan($jobTitlePos, $phonePos, 'phone at order=99 must come after job_title at order=0');
    }

    public function test_resolve_adds_group_from_field_config(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'grouped',
            'name' => 'Grouped',
            'field_config' => [
                ['code' => 'phone', 'required' => false, 'visible' => true, 'order' => 0, 'group' => 'contact', 'scope' => 'company_user'],
            ],
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'grouped',
        );

        $phoneField = collect($fields)->firstWhere('code', 'phone');
        $this->assertSame('contact', $phoneField['group']);

        // Unlisted field gets group=null
        $jobTitleField = collect($fields)->firstWhere('code', 'job_title');
        $this->assertNull($jobTitleField['group']);
    }

    public function test_resolve_ignores_unknown_codes_in_field_config(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'ghost',
            'name' => 'Ghost Refs',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'nonexistent_field', 'required' => true, 'visible' => true, 'order' => 1, 'scope' => 'company_user'],
            ],
        ]);

        $fields = FieldResolverService::resolve(
            $this->owner,
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'ghost',
        );

        $codes = array_column($fields, 'code');
        $this->assertNotContains('nonexistent_field', $codes, 'Unknown codes must be silently ignored');
        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes, 'Unlisted active field must remain visible');
    }

    // ═══════════════════════════════════════════════════════
    // Jobdomain seeding
    // ═══════════════════════════════════════════════════════

    public function test_jobdomain_assignment_seeds_field_config(): void
    {
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $driverRole = CompanyRole::where('company_id', $this->company->id)
            ->where('key', 'driver')
            ->first();

        $this->assertNotNull($driverRole);
        $this->assertNotNull($driverRole->field_config, 'Jobdomain assignment must seed field_config');
        $this->assertIsArray($driverRole->field_config);

        $codes = array_column($driverRole->field_config, 'code');
        $this->assertContains('phone', $codes);
    }

    public function test_jobdomain_assignment_does_not_overwrite_existing_field_config(): void
    {
        // Pre-create a role with custom field_config
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver Custom',
            'is_system' => true,
            'field_config' => [
                ['code' => 'phone', 'required' => false, 'visible' => true, 'order' => 99, 'scope' => 'company_user'],
            ],
        ]);

        JobdomainGate::assignToCompany($this->company, 'logistique');

        $driverRole = CompanyRole::where('company_id', $this->company->id)
            ->where('key', 'driver')
            ->first();

        // field_config must NOT be overwritten — company customization preserved
        $this->assertCount(1, $driverRole->field_config, 'Must not overwrite existing field_config');
        $this->assertSame(99, $driverRole->field_config[0]['order'], 'Custom order must be preserved');
    }

    // ═══════════════════════════════════════════════════════
    // Validation rules
    // ═══════════════════════════════════════════════════════

    public function test_validation_rules_respect_role_override(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'strict',
            'name' => 'Strict',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'job_title', 'required' => false, 'visible' => false, 'order' => 1, 'scope' => 'company_user'],
            ],
        ]);

        $rules = FieldValidationService::rules(
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
            'strict',
        );

        $this->assertArrayHasKey('dynamic_fields.phone', $rules);
        $this->assertContains('required', $rules['dynamic_fields.phone'], 'Role override required=true must apply');
        $this->assertArrayNotHasKey('dynamic_fields.job_title', $rules, 'visible=false must exclude from validation');
    }

    public function test_validation_rules_without_role_returns_all_fields(): void
    {
        $rules = FieldValidationService::rules(
            FieldDefinition::SCOPE_COMPANY_USER,
            $this->company->id,
        );

        $this->assertArrayHasKey('dynamic_fields.phone', $rules);
        $this->assertArrayHasKey('dynamic_fields.job_title', $rules);
    }

    // ═══════════════════════════════════════════════════════
    // Health check
    // ═══════════════════════════════════════════════════════

    public function test_health_check_detects_orphaned_references(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'stale',
            'name' => 'Stale Refs',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'deleted_field_xyz', 'required' => true, 'visible' => true, 'order' => 1, 'scope' => 'company_user'],
            ],
        ]);

        $result = FieldConfigHealthCheck::check($this->company->id);

        $this->assertFalse($result['healthy']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('deleted_field_xyz', $result['issues'][0]['field_code']);
        $this->assertSame('references_inactive_field', $result['issues'][0]['issue']);
    }

    public function test_health_check_returns_healthy_when_no_issues(): void
    {
        CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'clean',
            'name' => 'Clean',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
            ],
        ]);

        $result = FieldConfigHealthCheck::check($this->company->id);

        $this->assertTrue($result['healthy']);
        $this->assertEmpty($result['issues']);
    }

    // ═══════════════════════════════════════════════════════
    // CompanyRole model helper
    // ═══════════════════════════════════════════════════════

    public function test_field_config_for_scope_filters_correctly(): void
    {
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'scoped',
            'name' => 'Scoped',
            'field_config' => [
                ['code' => 'phone', 'scope' => 'company_user', 'required' => true, 'visible' => true, 'order' => 0],
                ['code' => 'siret', 'scope' => 'company', 'required' => false, 'visible' => true, 'order' => 1],
            ],
        ]);

        $cuFields = $role->fieldConfigFor('company_user');
        $this->assertCount(1, $cuFields);
        $this->assertSame('phone', $cuFields[0]['code']);

        $coFields = $role->fieldConfigFor('company');
        $this->assertCount(1, $coFields);
        $this->assertSame('siret', $coFields[0]['code']);
    }
}
