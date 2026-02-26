<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
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
        $this->company = Company::create(['name' => 'Jobdomain Co', 'slug' => 'jobdomain-co']);
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

    public function test_update_jobdomain_succeeds_with_jobdomain_manage(): void
    {
        $this->actAs($this->manager)
            ->putJson('/api/company/jobdomain', ['key' => 'logistique'])
            ->assertOk();
    }

    // ───────────────────────────────────────────────────────────
    // Nav: management without jobdomain.view → no Industry item
    // ───────────────────────────────────────────────────────────

    public function test_nav_hides_jobdomain_without_jobdomain_view(): void
    {
        $response = $this->actAs($this->limitedAdmin)->getJson('/api/nav');
        $response->assertOk();

        $keys = collect($response->json('groups'))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))
            ->toArray();

        $this->assertNotContains('company-jobdomain', $keys,
            'Management role without jobdomain.view must NOT see Industry nav item');
    }

    public function test_nav_shows_jobdomain_with_jobdomain_view(): void
    {
        $response = $this->actAs($this->manager)->getJson('/api/nav');
        $response->assertOk();

        $keys = collect($response->json('groups'))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))
            ->toArray();

        $this->assertContains('company-jobdomain', $keys,
            'Management role with jobdomain.view should see Industry nav item');
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
}
