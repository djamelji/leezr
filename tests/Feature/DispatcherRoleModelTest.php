<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-373: Dispatcher role model — is_administrative=true + generic multi-jobdomain pattern.
 *
 * Verifies that:
 * - Dispatcher (is_administrative=true) sees structure nav items via NavBuilder (management roleLevel)
 * - Dispatcher sees Members, Company Profile, Shipments, My Deliveries, Support
 * - Dispatcher does NOT see Billing, Roles, Modules, Audit (no permissions)
 * - Dispatcher can read members API (200) but NOT billing API (403)
 * - My-deliveries nav visible without operationalOnly flag (permission is the generic filter)
 */
class DispatcherRoleModelTest extends TestCase
{
    use RefreshDatabase;

    private User $dispatcher;
    private Company $company;
    private array $dispatcherPermKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();
        JobdomainRegistry::sync();

        $this->company = Company::create([
            'name' => 'Dispatcher Co',
            'slug' => 'dispatcher-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $jobdomain = Jobdomain::where('key', 'logistique')->first();
        $this->company->jobdomains()->attach($jobdomain->id);

        // Enable all company modules (with activation reasons for addons)
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );

            if ($def->type !== 'core') {
                CompanyModuleActivationReason::create([
                    'company_id' => $this->company->id,
                    'module_key' => $key,
                    'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                ]);
            }
        }

        // Dispatcher: is_administrative=true, with support, without billing/roles
        $this->dispatcherPermKeys = [
            'theme.view', 'theme.manage',
            'members.view', 'members.invite',
            'settings.view',
            'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign', 'shipments.view_own',
            'support.view', 'support.create',
        ];

        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => true,
        ]);

        $dispatcherRole->permissions()->sync(
            CompanyPermission::whereIn('key', $this->dispatcherPermKeys)->pluck('id')->toArray(),
        );

        $this->dispatcher = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->dispatcher->id,
            'role' => 'user',
            'company_role_id' => $dispatcherRole->id,
        ]);
    }

    private function actAs(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function extractNavKeys(): array
    {
        $groups = NavBuilder::forCompany($this->company, $this->dispatcherPermKeys, 'management');

        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $keys[] = $item['key'];
                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    // ═══════════════════════════════════════════════════════
    // NAVIGATION — Dispatcher sees correct items
    // ═══════════════════════════════════════════════════════

    public function test_dispatcher_sees_members_nav_item(): void
    {
        $keys = $this->extractNavKeys();
        $this->assertContains('members', $keys, 'Dispatcher should see Members nav item (has members.view).');
    }

    public function test_dispatcher_does_not_see_support_nav_item(): void
    {
        $keys = $this->extractNavKeys();
        $this->assertNotContains('support', $keys, 'Support module has no sidebar nav item — access via footer link only.');
    }

    public function test_dispatcher_does_not_see_my_deliveries_nav_item(): void
    {
        $keys = $this->extractNavKeys();
        $this->assertNotContains('my-deliveries', $keys, 'Dispatcher with shipments.view should NOT see my-deliveries in nav (excludePermission)');
    }

    public function test_dispatcher_sees_shipments_nav_item(): void
    {
        $keys = $this->extractNavKeys();
        $this->assertContains('shipments', $keys, 'Dispatcher should see Shipments nav item (has shipments.view).');
    }

    public function test_dispatcher_does_not_see_billing_nav_item(): void
    {
        $keys = $this->extractNavKeys();
        $this->assertNotContains('billing', $keys, 'Dispatcher should NOT see Billing nav item (no billing.manage).');
    }

    public function test_dispatcher_does_not_see_roles_nav_item(): void
    {
        $keys = $this->extractNavKeys();
        $this->assertNotContains('company-roles', $keys, 'Dispatcher should NOT see Roles nav item (no roles.view).');
    }

    // ═══════════════════════════════════════════════════════
    // API — Dispatcher access verification
    // ═══════════════════════════════════════════════════════

    public function test_dispatcher_can_read_members(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/company/members')->assertOk();
    }

    public function test_dispatcher_cannot_read_billing(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/billing/overview')->assertStatus(403);
    }

    public function test_dispatcher_can_access_my_deliveries(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/my-deliveries')->assertOk();
    }
}
