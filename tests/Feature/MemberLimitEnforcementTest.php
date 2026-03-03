<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

class MemberLimitEnforcementTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private CompanyRole $userRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        CompanyPermissionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->activateCompanyModules($this->company);

        $this->userRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'member',
            'name' => 'Member',
            'is_administrative' => false,
        ]);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ─── 1. CompanyEntitlements::memberLimit() ───────────────

    public function test_member_limit_returns_5_for_starter(): void
    {
        $this->assertEquals(5, CompanyEntitlements::memberLimit($this->company));
    }

    public function test_member_limit_returns_null_for_pro(): void
    {
        $this->company->update(['plan_key' => 'pro']);

        $this->assertNull(CompanyEntitlements::memberLimit($this->company));
    }

    public function test_member_limit_returns_null_for_business(): void
    {
        $this->company->update(['plan_key' => 'business']);

        $this->assertNull(CompanyEntitlements::memberLimit($this->company));
    }

    // ─── 2. Enforcement in store() ───────────────────────────

    public function test_starter_plan_blocks_member_when_limit_reached(): void
    {
        // Owner counts as 1, add 4 more to reach limit of 5
        for ($i = 0; $i < 4; $i++) {
            $user = User::factory()->create();
            $this->company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'user',
                'company_role_id' => $this->userRole->id,
            ]);
        }

        $this->assertEquals(5, $this->company->memberships()->count());

        // 6th member should be blocked
        $response = $this->actAs($this->owner)
            ->postJson('/api/company/members', [
                'email' => 'sixth@example.com',
                'company_role_id' => $this->userRole->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('limit', 5);
    }

    public function test_pro_plan_allows_unlimited_members(): void
    {
        $this->company->update(['plan_key' => 'pro']);

        // Add 11 more members (12 total with owner)
        for ($i = 0; $i < 11; $i++) {
            $user = User::factory()->create();
            $this->company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'user',
                'company_role_id' => $this->userRole->id,
            ]);
        }

        // 13th member should be allowed
        $response = $this->actAs($this->owner)
            ->postJson('/api/company/members', [
                'email' => 'thirteenth@example.com',
                'company_role_id' => $this->userRole->id,
            ]);

        $response->assertStatus(201);
    }

    public function test_starter_allows_member_under_limit(): void
    {
        // Owner = 1, so 1/5 — adding is fine
        $response = $this->actAs($this->owner)
            ->postJson('/api/company/members', [
                'email' => 'second@example.com',
                'company_role_id' => $this->userRole->id,
            ]);

        $response->assertStatus(201);
    }

    // ─── 3. Index includes quota ─────────────────────────────

    public function test_members_index_includes_member_quota(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/company/members');

        $response->assertOk()
            ->assertJsonStructure(['members', 'member_count', 'member_limit'])
            ->assertJsonPath('member_count', 1)
            ->assertJsonPath('member_limit', 5);
    }

    public function test_members_index_returns_null_limit_for_pro(): void
    {
        $this->company->update(['plan_key' => 'pro']);
        PlanRegistry::clearCache();

        $response = $this->actAs($this->owner)
            ->getJson('/api/company/members');

        $response->assertOk()
            ->assertJsonPath('member_limit', null);
    }

    // ─── 4. Public catalog includes limits ───────────────────

    public function test_public_catalog_includes_limits(): void
    {
        $catalog = PlanRegistry::publicCatalog();

        foreach ($catalog as $plan) {
            $this->assertArrayHasKey('limits', $plan);
            $this->assertArrayHasKey('members', $plan['limits']);
            $this->assertArrayHasKey('storage_quota_gb', $plan['limits']);
        }
    }

    // ─── 5. Company profile includes member_quota ────────────

    public function test_company_profile_includes_member_quota(): void
    {
        $response = $this->actAs($this->owner)
            ->getJson('/api/company');

        $response->assertOk()
            ->assertJsonStructure(['member_quota' => ['current', 'limit']])
            ->assertJsonPath('member_quota.current', 1)
            ->assertJsonPath('member_quota.limit', 5);
    }
}
