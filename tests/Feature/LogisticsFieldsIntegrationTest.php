<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-164/171 Logistics: Integration tests for field catalog,
 * role-specific resolution, sensitive masking, and artisan sync.
 */
class LogisticsFieldsIntegrationTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $admin;
    private User $member;
    private Company $company;
    private $adminMembership;
    private $memberMembership;
    private CompanyRole $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create(['name' => 'Logistics Co', 'slug' => 'logistics-co', 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $this->adminRole = $this->setUpCompanyRbac($this->company);

        $this->adminMembership = $this->company->memberships()->create([
            'user_id' => $this->admin->id,
            'role' => 'owner',
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
        ]);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // Catalog completeness
    // ═══════════════════════════════════════════════════════

    public function test_catalog_contains_20_company_user_fields(): void
    {
        $cuFields = collect(FieldDefinitionCatalog::all())
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER);

        $this->assertCount(20, $cuFields, 'Catalog must contain exactly 20 company_user fields (canon FR logistique)');
    }

    public function test_catalog_fields_synced_to_database(): void
    {
        $dbCount = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->count();

        $this->assertGreaterThanOrEqual(20, $dbCount, 'Database must contain at least 20 synced company_user field definitions');
    }

    public function test_sensitive_fields_are_flagged(): void
    {
        $sensitiveFields = FieldDefinition::whereNull('company_id')
            ->whereIn('code', FieldResolverService::SENSITIVE_CODES)
            ->get();

        $this->assertCount(2, $sensitiveFields);

        foreach ($sensitiveFields as $field) {
            $this->assertTrue(
                $field->validation_rules['sensitive'] ?? false,
                "Field '{$field->code}' must have sensitive=true in validation_rules"
            );
        }
    }

    // ═══════════════════════════════════════════════════════
    // JobdomainGate::assignToCompany — field activation
    // ═══════════════════════════════════════════════════════

    public function test_assign_logistics_jobdomain_activates_all_fields(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $definition = JobdomainRegistry::get('logistique');
        $expectedCodes = collect($definition['default_fields'])->pluck('code')->sort()->values();

        $activatedCodes = FieldActivation::where('company_id', $this->company->id)
            ->where('enabled', true)
            ->get()
            ->map(fn ($a) => $a->definition->code)
            ->sort()
            ->values();

        $this->assertEquals($expectedCodes->toArray(), $activatedCodes->toArray());
    }

    public function test_assign_logistics_seeds_four_roles_with_field_config(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $roles = CompanyRole::where('company_id', $this->company->id)
            ->whereIn('key', ['manager', 'dispatcher', 'driver', 'ops_manager'])
            ->get()
            ->keyBy('key');

        $this->assertCount(4, $roles);

        foreach (['manager', 'dispatcher', 'driver', 'ops_manager'] as $roleKey) {
            $role = $roles->get($roleKey);
            $this->assertNotNull($role, "Role '{$roleKey}' must exist");
            $this->assertNotNull($role->field_config, "Role '{$roleKey}' must have field_config");
            $this->assertGreaterThan(2, count($role->field_config), "Role '{$roleKey}' must have more than 2 field_config entries");
        }
    }

    // ═══════════════════════════════════════════════════════
    // Role-specific field resolution
    // ═══════════════════════════════════════════════════════

    public function test_driver_role_hides_management_fields(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'driver',
        );

        $codes = array_column($fields, 'code');

        // Driver should see driving-specific fields
        $this->assertContains('license_number', $codes);
        $this->assertContains('license_category', $codes);

        // Driver should NOT see dispatch fields
        $this->assertNotContains('geographic_zone', $codes);
        $this->assertNotContains('work_schedule', $codes);
        $this->assertNotContains('work_mode', $codes);
    }

    public function test_dispatcher_role_hides_driver_and_management_fields(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'dispatcher',
        );

        $codes = array_column($fields, 'code');

        // Dispatcher should see dispatch fields
        $this->assertContains('geographic_zone', $codes);
        $this->assertContains('work_schedule', $codes);
        $this->assertContains('work_mode', $codes);

        // Dispatcher should NOT see driving fields
        $this->assertNotContains('license_number', $codes);
        $this->assertNotContains('license_category', $codes);
    }

    public function test_manager_role_sees_base_hides_driving_and_dispatch(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'manager',
        );

        $codes = array_column($fields, 'code');

        // Manager should see base/hr fields
        $this->assertContains('job_title', $codes);
        $this->assertContains('hire_date', $codes);
        $this->assertContains('phone', $codes);

        // Manager should NOT see driving/dispatch fields
        $this->assertNotContains('license_number', $codes);
        $this->assertNotContains('geographic_zone', $codes);
        $this->assertNotContains('work_mode', $codes);
    }

    public function test_ops_manager_has_partial_driving_visibility(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'ops_manager',
        );

        $codes = array_column($fields, 'code');

        // Ops manager sees partial driving overview (license_category, vehicle_type)
        $this->assertContains('license_category', $codes);
        $this->assertContains('vehicle_type', $codes);

        // Ops manager sees partial dispatch overview (geographic_zone)
        $this->assertContains('geographic_zone', $codes);

        // Ops manager does NOT see detailed driving fields
        $this->assertNotContains('license_number', $codes);
        $this->assertNotContains('adr_certified', $codes);
    }

    public function test_all_roles_see_base_commune_fields(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $baseCodes = ['phone', 'address', 'emergency_contact_name', 'birth_date',
            'social_security_number', 'iban', 'contract_type', 'hire_date', 'employee_status'];

        foreach (['manager', 'dispatcher', 'driver', 'ops_manager'] as $roleKey) {
            $fields = FieldResolverService::resolve(
                model: $this->member,
                scope: FieldDefinition::SCOPE_COMPANY_USER,
                companyId: $this->company->id,
                roleKey: $roleKey,
            );

            $codes = array_column($fields, 'code');

            foreach ($baseCodes as $baseCode) {
                $this->assertContains($baseCode, $codes, "Role '{$roleKey}' must see base commune field '{$baseCode}'");
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // Sensitive data masking
    // ═══════════════════════════════════════════════════════

    public function test_sensitive_fields_masked_when_no_permission(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        // Store sensitive values
        $ibanDef = FieldDefinition::whereNull('company_id')->where('code', 'iban')->first();
        $ssnDef = FieldDefinition::whereNull('company_id')->where('code', 'social_security_number')->first();

        FieldValue::create([
            'model_type' => $this->member->getMorphClass(),
            'model_id' => $this->member->id,
            'field_definition_id' => $ibanDef->id,
            'value' => 'FR7630006000011234567890189',
        ]);
        FieldValue::create([
            'model_type' => $this->member->getMorphClass(),
            'model_id' => $this->member->id,
            'field_definition_id' => $ssnDef->id,
            'value' => '1850175123456',
        ]);

        // Resolve WITHOUT sensitive permission
        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            canReadSensitive: false,
        );

        $fieldsByCode = collect($fields)->keyBy('code');

        $this->assertStringEndsWith('0189', $fieldsByCode['iban']['value']);
        $this->assertStringStartsWith('***', $fieldsByCode['iban']['value']);
        $this->assertTrue($fieldsByCode['iban']['masked']);

        $this->assertStringEndsWith('3456', $fieldsByCode['social_security_number']['value']);
        $this->assertTrue($fieldsByCode['social_security_number']['masked']);
    }

    public function test_sensitive_fields_unmasked_when_permission_granted(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $ibanDef = FieldDefinition::whereNull('company_id')->where('code', 'iban')->first();
        FieldValue::create([
            'model_type' => $this->member->getMorphClass(),
            'model_id' => $this->member->id,
            'field_definition_id' => $ibanDef->id,
            'value' => 'FR7630006000011234567890189',
        ]);

        // Resolve WITH sensitive permission
        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            canReadSensitive: true,
        );

        $ibanField = collect($fields)->firstWhere('code', 'iban');

        $this->assertSame('FR7630006000011234567890189', $ibanField['value']);
        $this->assertArrayNotHasKey('masked', $ibanField);
    }

    public function test_http_masking_for_non_admin_member(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        // Store IBAN value
        $ibanDef = FieldDefinition::whereNull('company_id')->where('code', 'iban')->first();
        FieldValue::create([
            'model_type' => $this->member->getMorphClass(),
            'model_id' => $this->member->id,
            'field_definition_id' => $ibanDef->id,
            'value' => 'FR7630006000011234567890189',
        ]);

        // Create a role WITHOUT sensitive_read permission
        $viewerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'viewer',
            'name' => 'Viewer',
            'is_administrative' => false,
        ]);

        $viewPerm = CompanyPermission::where('key', 'members.view')->first();
        if ($viewPerm) {
            $viewerRole->permissions()->sync([$viewPerm->id]);
        }

        $viewer = User::factory()->create();
        $viewerMembership = $this->company->memberships()->create([
            'user_id' => $viewer->id,
            'role' => 'user',
            'company_role_id' => $viewerRole->id,
        ]);

        $response = $this->actAs($viewer)
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $response->assertOk();

        $ibanField = collect($response->json('dynamic_fields'))->firstWhere('code', 'iban');

        if ($ibanField) {
            $this->assertStringStartsWith('***', $ibanField['value']);
            $this->assertTrue($ibanField['masked']);
        }
    }

    // ═══════════════════════════════════════════════════════
    // Artisan sync command
    // ═══════════════════════════════════════════════════════

    public function test_sync_command_activates_missing_fields(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        // Record initial count
        $initialCount = FieldActivation::where('company_id', $this->company->id)->count();

        // Delete a few activations to simulate missing fields
        $toDelete = FieldActivation::where('company_id', $this->company->id)
            ->take(5)
            ->pluck('id');
        FieldActivation::whereIn('id', $toDelete)->delete();

        $afterDeleteCount = FieldActivation::where('company_id', $this->company->id)->count();
        $this->assertEquals($initialCount - 5, $afterDeleteCount);

        // Run sync command
        Artisan::call('jobdomain:sync-logistics-fields');

        $afterSyncCount = FieldActivation::where('company_id', $this->company->id)->count();
        $this->assertEquals($initialCount, $afterSyncCount, 'Sync command should restore missing activations');
    }

    public function test_sync_command_dry_run_does_not_modify(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        // Delete some activations
        FieldActivation::where('company_id', $this->company->id)->take(3)->delete();
        $beforeCount = FieldActivation::where('company_id', $this->company->id)->count();

        Artisan::call('jobdomain:sync-logistics-fields', ['--dry-run' => true]);

        $afterCount = FieldActivation::where('company_id', $this->company->id)->count();
        $this->assertEquals($beforeCount, $afterCount, 'Dry run should not create any activations');
    }

    // ═══════════════════════════════════════════════════════
    // Permission catalog
    // ═══════════════════════════════════════════════════════

    public function test_sensitive_read_permission_exists(): void
    {
        $perm = CompanyPermission::where('key', 'members.sensitive_read')->first();

        $this->assertNotNull($perm, 'members.sensitive_read permission must exist');
        $this->assertTrue($perm->is_admin);
    }

    public function test_sensitive_data_bundle_exists(): void
    {
        $bundles = \App\Core\Modules\ModuleRegistry::definitions()['core.members']->bundles;
        $bundleKeys = array_column($bundles, 'key');

        $this->assertContains('members.sensitive_data', $bundleKeys);
    }

    // ═══════════════════════════════════════════════════════
    // Field groups
    // ═══════════════════════════════════════════════════════

    public function test_driver_fields_have_groups(): void
    {
        JobdomainRegistry::sync();
        JobdomainGate::assignToCompany($this->company, 'logistique');

        $fields = FieldResolverService::resolve(
            model: $this->member,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $this->company->id,
            roleKey: 'driver',
        );

        $groups = array_unique(array_filter(array_column($fields, 'group')));
        sort($groups);

        $this->assertContains('contact', $groups);
        $this->assertContains('identity', $groups);
        $this->assertContains('hr', $groups);
        $this->assertContains('driving', $groups);
    }
}
