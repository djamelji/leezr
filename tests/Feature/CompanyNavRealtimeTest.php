<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves that the /api/nav endpoint reflects permission changes
 * immediately on the next request — no cache, no stale data.
 *
 * LOT 3: RBAC realtime nav (sans websockets).
 */
class CompanyNavRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $alice;
    private Company $company;
    private CompanyRole $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Realtime Co',
            'slug' => 'realtime-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        // Enable all company modules
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // Create an administrative role with ALL permissions (including billing.manage)
        $this->role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'full_admin',
            'name' => 'Full Admin',
            'is_administrative' => true,
        ]);

        $this->role->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        // Users
        $this->owner = User::factory()->create();
        $this->alice = User::factory()->create();

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->alice->id,
            'role' => 'user',
            'company_role_id' => $this->role->id,
        ]);
    }

    public function test_nav_reflects_permission_removal_on_next_request(): void
    {
        // Step 1: Alice has billing.manage → should see 'plan' nav item
        $navBefore = $this->navKeysFor($this->alice);
        $this->assertContains('plan', $navBefore, 'Alice should see plan (has billing.manage)');

        // Step 2: Owner removes billing.manage from Alice's role
        $billingPermId = CompanyPermission::where('key', 'billing.manage')->value('id');
        $currentPerms = $this->role->permissions()->pluck('company_permissions.id')->toArray();
        $newPerms = array_values(array_filter($currentPerms, fn ($id) => $id !== $billingPermId));
        $this->role->permissions()->sync($newPerms);

        // Step 3: Alice's VERY NEXT /api/nav call should NOT include 'plan'
        $navAfter = $this->navKeysFor($this->alice);
        $this->assertNotContains('plan', $navAfter, 'After removing billing.manage, Alice should NOT see plan');
    }

    public function test_nav_reflects_permission_addition_on_next_request(): void
    {
        // Start with a stripped-down role (no billing.manage)
        $nonBillingPerms = CompanyPermission::where('key', '!=', 'billing.manage')->pluck('id')->toArray();
        $this->role->permissions()->sync($nonBillingPerms);

        // Step 1: Alice without billing.manage → no 'plan'
        $navBefore = $this->navKeysFor($this->alice);
        $this->assertNotContains('plan', $navBefore, 'Alice should NOT see plan without billing.manage');

        // Step 2: Add billing.manage back
        $this->role->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        // Step 3: Immediate next call reflects the change
        $navAfter = $this->navKeysFor($this->alice);
        $this->assertContains('plan', $navAfter, 'After adding billing.manage, Alice should see plan');
    }

    public function test_my_companies_reflects_role_level_change_on_next_request(): void
    {
        // Step 1: Alice's role is administrative
        $response = $this->actingAs($this->alice)->getJson('/api/my-companies');
        $company = collect($response->json('companies'))->firstWhere('id', $this->company->id);
        $this->assertTrue($company['is_administrative']);

        // Step 2: Downgrade role to operational
        $this->role->update(['is_administrative' => false]);

        // Step 3: Immediate next call reflects the change
        $response = $this->actingAs($this->alice)->getJson('/api/my-companies');
        $company = collect($response->json('companies'))->firstWhere('id', $this->company->id);
        $this->assertFalse($company['is_administrative']);
    }

    public function test_nav_reflects_role_level_downgrade_hides_structure_items(): void
    {
        // Step 1: Administrative role → Alice sees structure items (settings)
        $navBefore = $this->navKeysFor($this->alice);
        $this->assertContains('company-profile', $navBefore, 'Admin Alice should see company-profile');

        // Step 2: Downgrade to operational
        $this->role->update(['is_administrative' => false]);

        // Step 3: Structure items hidden for operational users on next request
        $navAfter = $this->navKeysFor($this->alice);
        $this->assertNotContains('company-profile', $navAfter, 'Operational Alice should NOT see company-profile');
    }

    // ─── Helpers ─────────────────────────────────────────

    private function navKeysFor(User $user): array
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        $keys = [];
        foreach ($response->json('groups') ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $keys[] = $item['key'];
                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
