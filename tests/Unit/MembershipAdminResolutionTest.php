<?php

namespace Tests\Unit;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membership::isAdmin() resolution tests.
 *
 * Validates the 2-tier logic:
 *   1. Owner → always admin
 *   2. CompanyRole assigned → is_administrative flag is source of truth
 *   3. No CompanyRole → NOT admin (no fallback)
 */
class MembershipAdminResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Test Co', 'slug' => 'test-co', 'jobdomain_key' => 'logistique']);
    }

    // ═══════════════════════════════════════════════════════
    // Tier 1: Owner always admin
    // ═══════════════════════════════════════════════════════

    public function test_owner_is_always_admin(): void
    {
        $user = User::factory()->create();
        $membership = $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->assertTrue($membership->isAdmin());
        $this->assertTrue($membership->isOwner());
    }

    public function test_owner_is_admin_even_with_non_admin_company_role(): void
    {
        $user = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'company_role_id' => $role->id,
        ]);

        $this->assertTrue($membership->isAdmin());
    }

    // ═══════════════════════════════════════════════════════
    // Tier 2: CompanyRole is source of truth when assigned
    // ═══════════════════════════════════════════════════════

    public function test_user_with_administrative_company_role_is_admin(): void
    {
        $user = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $this->assertTrue($membership->isAdmin());
    }

    public function test_user_with_non_administrative_company_role_is_not_admin(): void
    {
        $user = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => false,
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        $this->assertFalse($membership->isAdmin());
    }

    public function test_user_role_with_admin_company_role_is_admin(): void
    {
        $user = User::factory()->create();
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $membership = $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'company_role_id' => $role->id,
        ]);

        // CompanyRole.is_administrative is the sole source of truth
        $this->assertTrue($membership->isAdmin());
    }

    // ═══════════════════════════════════════════════════════
    // Tier 3: No CompanyRole → NOT admin (no fallback)
    // ═══════════════════════════════════════════════════════

    public function test_user_without_company_role_is_not_admin(): void
    {
        $user = User::factory()->create();
        $membership = $this->company->memberships()->create([
            'user_id' => $user->id,
            'role' => 'user',
        ]);

        // No CompanyRole → NOT admin (no legacy fallback)
        $this->assertFalse($membership->isAdmin());
    }
}
