<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * Tests for core.jobdomain module — permission-based API access.
 *
 * Validates:
 *   - 403 on jobdomain API without jobdomain.view / jobdomain.manage
 *   - Owner bypass intact
 *   - Management without jobdomain.view cannot see Industry nav item
 *   - Permission catalog includes jobdomain.* permissions
 */
class CompanyJobdomainModuleTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private User $owner;
    private User $manager;
    private User $limitedAdmin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Jobdomain Co', 'slug' => 'jobdomain-co', 'jobdomain_key' => 'logistique']);
        JobdomainRegistry::sync();
        $this->activateCompanyModules($this->company);
        $this->setUpCompanyRbac($this->company);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Manager: management role WITH jobdomain.view + jobdomain.manage
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'full_mgr',
            'name' => 'Full Manager',
            'is_administrative' => true,
        ]);

        $allPerms = CompanyPermission::pluck('id')->toArray();
        $managerRole->permissions()->sync($allPerms);

        $this->manager = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->manager->id,
            'role' => 'user',
            'company_role_id' => $managerRole->id,
        ]);

        // Limited admin: management but WITHOUT jobdomain.*
        $limitedRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'limited_mgr',
            'name' => 'Limited Manager',
            'is_administrative' => true,
        ]);

        $nonJobdomainPerms = CompanyPermission::where('key', 'not like', 'jobdomain.%')
            ->pluck('id')->toArray();
        $limitedRole->permissions()->sync($nonJobdomainPerms);

        $this->limitedAdmin = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->limitedAdmin->id,
            'role' => 'user',
            'company_role_id' => $limitedRole->id,
        ]);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ───────────────────────────────────────────────────────────
    // GET /company/jobdomain — requires jobdomain.view
    // ───────────────────────────────────────────────────────────

    public function test_get_jobdomain_returns_403_without_jobdomain_view(): void
    {
        $this->actAs($this->limitedAdmin)
            ->getJson('/api/company/jobdomain')
            ->assertForbidden();
    }

    public function test_get_jobdomain_succeeds_with_jobdomain_view(): void
    {
        $this->actAs($this->manager)
            ->getJson('/api/company/jobdomain')
            ->assertOk();
    }

    public function test_get_jobdomain_owner_bypass(): void
    {
        $this->actAs($this->owner)
            ->getJson('/api/company/jobdomain')
            ->assertOk();
    }

    // ───────────────────────────────────────────────────────────
    // PUT /company/jobdomain — requires jobdomain.manage
    // ───────────────────────────────────────────────────────────

    public function test_update_jobdomain_returns_403_without_jobdomain_manage(): void
    {
        $this->actAs($this->limitedAdmin)
            ->putJson('/api/company/jobdomain', ['key' => 'logistique'])
            ->assertForbidden();
    }

    public function test_update_jobdomain_returns_422_when_already_assigned(): void
    {
        // ADR-167a: jobdomain is always present → immutability guard always triggers
        $this->actAs($this->manager)
            ->putJson('/api/company/jobdomain', ['key' => 'logistique'])
            ->assertStatus(422);
    }

    // ───────────────────────────────────────────────────────────
    // ADR-134: No nav item (page removed, info shown in plan)
    // ───────────────────────────────────────────────────────────

    public function test_no_jobdomain_nav_item_after_page_removal(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/nav');
        $response->assertOk();

        $keys = collect($response->json('groups'))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))
            ->toArray();

        $this->assertNotContains('company-jobdomain', $keys,
            'Jobdomain nav item must not exist (page removed, info integrated into plan)');
    }

    // ───────────────────────────────────────────────────────────
    // Permission catalog includes jobdomain.* permissions
    // ───────────────────────────────────────────────────────────

    public function test_permission_catalog_includes_jobdomain_permissions(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/company/permissions');
        $response->assertOk();

        $permKeys = collect($response->json('permissions'))->pluck('key')->toArray();

        $this->assertContains('jobdomain.view', $permKeys);
        $this->assertContains('jobdomain.manage', $permKeys);
    }

    public function test_permission_catalog_includes_jobdomain_bundles(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/company/permissions');
        $response->assertOk();

        $modules = $response->json('modules');
        $jdModule = collect($modules)->firstWhere('module_key', 'core.jobdomain');

        $this->assertNotNull($jdModule, 'core.jobdomain module must appear in catalog');

        $bundleKeys = collect($jdModule['capabilities'])->pluck('key')->toArray();
        $this->assertContains('jobdomain.info', $bundleKeys);
        $this->assertContains('jobdomain.management', $bundleKeys);
    }

    // ───────────────────────────────────────────────────────────
    // ADR-134: Jobdomain immutability once assigned
    // ───────────────────────────────────────────────────────────

    public function test_cannot_change_jobdomain_once_assigned(): void
    {
        // Assign jobdomain first
        JobdomainGate::assignToCompany($this->company, 'logistique');

        // Attempt to change → must be rejected
        $this->actAs($this->owner)
            ->putJson('/api/company/jobdomain', ['key' => 'logistique'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Jobdomain cannot be changed once assigned. Contact support or create a new company.']);
    }

    public function test_jobdomain_is_always_present(): void
    {
        // ADR-167a: jobdomain_key is a structural invariant — always present
        $this->assertNotNull($this->company->jobdomain_key);
        $this->assertEquals('logistique', $this->company->jobdomain_key);
    }
}
