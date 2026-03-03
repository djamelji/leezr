<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-164 Step 2: HTTP-level tests for role-aware member fields.
 *
 * - GET /company/members/{id} returns role-filtered dynamic_fields
 * - GET /company/members/{id}/fields?role_key=xxx returns preview
 * - PUT /company/members/{id} validates against role-specific rules
 * - PUT /company/roles/{id} accepts field_config
 * - Field values preserved on role change
 */
class MemberRoleAwareFieldTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $admin;
    private User $member;
    private Company $company;
    private $adminMembership;
    private $memberMembership;
    private CompanyRole $adminRole;
    private CompanyRole $driverRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->company = Company::create(['name' => 'Field Test Co', 'slug' => 'field-test-co', 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $this->adminRole = $this->setUpCompanyRbac($this->company);

        // Create driver role with field_config: phone visible+required, job_title hidden
        $this->driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'job_title', 'required' => false, 'visible' => false, 'order' => 1, 'scope' => 'company_user'],
            ],
        ]);

        $this->adminMembership = $this->company->memberships()->create([
            'user_id' => $this->admin->id,
            'role' => 'owner',
        ]);

        $this->memberMembership = $this->company->memberships()->create([
            'user_id' => $this->member->id,
            'role' => 'user',
            'company_role_id' => $this->driverRole->id,
        ]);

        // Activate company_user fields (phone, job_title)
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

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /company/members/{id} — role-filtered
    // ═══════════════════════════════════════════════════════

    public function test_show_member_returns_role_filtered_fields(): void
    {
        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $response->assertOk();

        $codes = array_column($response->json('dynamic_fields'), 'code');

        // phone is visible, job_title is hidden by driver role's field_config
        $this->assertContains('phone', $codes);
        $this->assertNotContains('job_title', $codes, 'job_title should be hidden by driver field_config');
    }

    public function test_show_member_without_role_returns_all_fields(): void
    {
        // Remove company_role from member
        $this->memberMembership->update(['company_role_id' => null]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $response->assertOk();

        $codes = array_column($response->json('dynamic_fields'), 'code');

        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes, 'Without role, all fields must be visible');
    }

    // ═══════════════════════════════════════════════════════
    // GET /company/members/{id}/fields — preview
    // ═══════════════════════════════════════════════════════

    public function test_preview_fields_for_role(): void
    {
        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}/fields?role_key=driver");

        $response->assertOk();

        $codes = array_column($response->json('dynamic_fields'), 'code');

        $this->assertContains('phone', $codes);
        $this->assertNotContains('job_title', $codes);
    }

    public function test_preview_fields_without_role_key_returns_all(): void
    {
        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}/fields");

        $response->assertOk();

        $codes = array_column($response->json('dynamic_fields'), 'code');

        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes);
    }

    // ═══════════════════════════════════════════════════════
    // PUT /company/members/{id} — role-aware validation
    // ═══════════════════════════════════════════════════════

    public function test_update_member_with_role_change_uses_new_role_for_response(): void
    {
        // Create a manager role with all fields visible
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'field_config' => [
                ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
                ['code' => 'job_title', 'required' => true, 'visible' => true, 'order' => 1, 'scope' => 'company_user'],
            ],
        ]);

        $response = $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'company_role_id' => $managerRole->id,
            ]);

        $response->assertOk();

        // After role change to manager, response should include job_title (visible for manager)
        $codes = array_column($response->json('dynamic_fields'), 'code');

        $this->assertContains('phone', $codes);
        $this->assertContains('job_title', $codes, 'After changing to manager role, job_title should be visible');
    }

    public function test_field_values_preserved_on_role_change(): void
    {
        // First, set a job_title value for the member (with no role so all fields visible)
        $this->memberMembership->update(['company_role_id' => null]);

        $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'dynamic_fields' => ['job_title' => 'Senior Driver'],
            ])
            ->assertOk();

        // Now assign the driver role (which hides job_title)
        $this->actAs($this->admin)
            ->putJson("/api/company/members/{$this->memberMembership->id}", [
                'company_role_id' => $this->driverRole->id,
            ])
            ->assertOk();

        // Remove the role again — job_title value should still be preserved
        // Must refresh from DB first (API updated the DB row, local model is stale)
        $this->memberMembership = $this->memberMembership->fresh();
        $this->memberMembership->update(['company_role_id' => null]);

        $response = $this->actAs($this->admin)
            ->getJson("/api/company/members/{$this->memberMembership->id}");

        $jobTitle = collect($response->json('dynamic_fields'))->firstWhere('code', 'job_title');

        $this->assertSame('Senior Driver', $jobTitle['value'], 'FieldValue must persist even after role change hiding it');
    }

    // ═══════════════════════════════════════════════════════
    // PUT /company/roles/{id} — field_config CRUD
    // ═══════════════════════════════════════════════════════

    public function test_company_role_crud_accepts_field_config(): void
    {
        $fieldConfig = [
            ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
        ];

        $response = $this->actAs($this->admin)
            ->putJson("/api/company/roles/{$this->driverRole->id}", [
                'field_config' => $fieldConfig,
            ]);

        $response->assertOk();

        $this->driverRole->refresh();
        $this->assertCount(1, $this->driverRole->field_config);
        $this->assertSame('phone', $this->driverRole->field_config[0]['code']);
    }

    public function test_company_role_create_with_field_config(): void
    {
        $fieldConfig = [
            ['code' => 'phone', 'required' => false, 'visible' => true, 'order' => 0, 'scope' => 'company_user'],
            ['code' => 'job_title', 'required' => true, 'visible' => true, 'order' => 1, 'scope' => 'company_user'],
        ];

        $response = $this->actAs($this->admin)
            ->postJson('/api/company/roles', [
                'name' => 'Coordinator',
                'field_config' => $fieldConfig,
            ]);

        $response->assertCreated();

        $role = CompanyRole::where('key', 'coordinator')->first();

        $this->assertNotNull($role);
        $this->assertCount(2, $role->field_config);
    }
}
