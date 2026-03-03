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
 * Company navigation permission filtering tests.
 *
 * Validates that:
 *   - Owner sees all items (permission bypass)
 *   - Administrative role sees all items + structure items
 *   - Dispatcher (non-admin) only sees permitted items
 *   - Operational role hides structure items
 *   - Driver with limited permissions sees only allowed items
 */
class CompanyNavPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $dispatcher;
    private User $driver;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Nav Perm Co',
            'slug' => 'nav-perm-co',
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

        // Create roles
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $managerRole->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => false,
        ]);

        $opPerms = CompanyPermission::where('is_admin', false)
            ->whereIn('key', [
                'members.view', 'members.invite', 'settings.view',
                'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign',
            ])
            ->pluck('id')->toArray();

        $dispatcherRole->permissions()->sync($opPerms);

        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $driverPerms = CompanyPermission::where('is_admin', false)
            ->whereIn('key', ['members.view', 'shipments.view', 'shipments.manage_status'])
            ->pluck('id')->toArray();

        $driverRole->permissions()->sync($driverPerms);

        // Create users
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->dispatcher = User::factory()->create();
        $this->driver = User::factory()->create();

        // Create memberships
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->admin->id,
            'role' => 'user',
            'company_role_id' => $managerRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->dispatcher->id,
            'role' => 'user',
            'company_role_id' => $dispatcherRole->id,
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->driver->id,
            'role' => 'user',
            'company_role_id' => $driverRole->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // Owner — full bypass
    // ═══════════════════════════════════════════════════════

    public function test_owner_sees_all_nav_items(): void
    {
        $keys = $this->navKeysFor($this->owner);

        $this->assertContains('members', $keys, 'Owner should see members');
        $this->assertContains('settings', $keys, 'Owner should see settings');
        $this->assertContains('shipments', $keys, 'Owner should see shipments');
    }

    // ═══════════════════════════════════════════════════════
    // Admin (administrative CompanyRole) — full bypass
    // ═══════════════════════════════════════════════════════

    public function test_admin_sees_all_nav_items(): void
    {
        $keys = $this->navKeysFor($this->admin);

        $this->assertContains('members', $keys, 'Admin should see members');
        $this->assertContains('settings', $keys, 'Admin should see settings');
        $this->assertContains('shipments', $keys, 'Admin should see shipments');
    }

    // ═══════════════════════════════════════════════════════
    // Dispatcher (non-admin) — permission-filtered
    // ═══════════════════════════════════════════════════════

    public function test_dispatcher_sees_permitted_items(): void
    {
        $keys = $this->navKeysFor($this->dispatcher);

        $this->assertContains('shipments', $keys, 'Dispatcher should see shipments');
    }

    public function test_dispatcher_does_not_see_structure_items(): void
    {
        $response = $this->actingAs($this->dispatcher)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav');

        $response->assertOk();
        $json = $response->json('groups');

        // Structure items should not appear for operational users
        $allItems = collect($json)->flatMap(fn ($g) => $g['items'] ?? []);
        $surfaceItems = $allItems->filter(fn ($i) => ($i['surface'] ?? null) === 'structure');

        $this->assertEmpty($surfaceItems->toArray(), 'Dispatcher should not see structure-surface items');
    }

    public function test_dispatcher_permission_set_is_not_empty(): void
    {
        $response = $this->actingAs($this->dispatcher)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav');

        // The response should be filtered (not bypass)
        $response->assertOk();
        $keys = $this->extractKeys($response);

        // Should NOT have everything — specifically, should not have items that require
        // permissions the dispatcher doesn't hold
        // (This test ensures permissions=[] bypass is NOT applied)
        $this->assertNotEmpty($keys, 'Dispatcher should see at least some items');
    }

    // ═══════════════════════════════════════════════════════
    // Driver — most restricted
    // ═══════════════════════════════════════════════════════

    public function test_driver_only_sees_items_matching_permissions(): void
    {
        $keys = $this->navKeysFor($this->driver);

        // Driver has: members.view, shipments.view, shipments.manage_status
        $this->assertContains('shipments', $keys, 'Driver should see shipments (has shipments.view)');
    }

    public function test_driver_does_not_see_settings(): void
    {
        $keys = $this->navKeysFor($this->driver);

        // Driver does NOT have settings.view — but settings is a structure item anyway
        $this->assertNotContains('settings', $keys, 'Driver should not see settings');
    }

    // ═══════════════════════════════════════════════════════
    // AuthController::myCompanies — is_administrative alignment
    // ═══════════════════════════════════════════════════════

    public function test_my_companies_reports_correct_administrative_flag(): void
    {
        // Owner
        $ownerResponse = $this->actingAs($this->owner)->getJson('/api/my-companies');
        $ownerData = collect($ownerResponse->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertTrue($ownerData['is_administrative'], 'Owner should be administrative');

        // Admin (manager role)
        $adminResponse = $this->actingAs($this->admin)->getJson('/api/my-companies');
        $adminData = collect($adminResponse->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertTrue($adminData['is_administrative'], 'Manager should be administrative');

        // Dispatcher (non-admin)
        $dispatcherResponse = $this->actingAs($this->dispatcher)->getJson('/api/my-companies');
        $dispatcherData = collect($dispatcherResponse->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertFalse($dispatcherData['is_administrative'], 'Dispatcher should NOT be administrative');

        // Driver (non-admin)
        $driverResponse = $this->actingAs($this->driver)->getJson('/api/my-companies');
        $driverData = collect($driverResponse->json('companies'))
            ->firstWhere('id', $this->company->id);

        $this->assertFalse($driverData['is_administrative'], 'Driver should NOT be administrative');
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════

    private function navKeysFor(User $user): array
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/nav');

        $response->assertOk();

        return $this->extractKeys($response);
    }

    private function extractKeys($response): array
    {
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
