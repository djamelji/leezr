<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardCatalogService;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-374: Operations widgets for logistics.
 *
 * Verifies:
 * - 8 operations widgets registered in the catalog
 * - Catalog filtering by archetype + permissions
 * - Server-resolved widgets return correct data
 * - Widget counts per role (Manager, Dispatcher, Driver)
 */
class OperationsWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $dispatcher;
    private User $driver;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();
        JobdomainRegistry::sync();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        $this->company = Company::create([
            'name' => 'Ops Widget Co',
            'slug' => 'ops-widget-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $jobdomain = Jobdomain::where('key', 'logistique')->first();
        $this->company->jobdomains()->attach($jobdomain->id);

        // Enable all company modules (including logistics_shipments)
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

        // Manager: management archetype, has shipments.view + shipments.assign
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'archetype' => 'management',
            'is_administrative' => true,
        ]);

        $managerPerms = CompanyPermission::whereIn('key', [
            'theme.view', 'theme.manage',
            'members.view', 'members.invite', 'members.manage', 'members.credentials', 'members.sensitive_read',
            'settings.view', 'settings.manage',
            'roles.view', 'roles.manage',
            'billing.manage',
            'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign',
            'support.view', 'support.create',
        ])->pluck('id')->toArray();

        $managerRole->permissions()->sync($managerPerms);

        // Dispatcher: operations_center archetype, has shipments.view + assign + view_own
        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'archetype' => 'operations_center',
            'is_administrative' => true,
        ]);

        $dispatcherPerms = CompanyPermission::whereIn('key', [
            'theme.view', 'theme.manage',
            'members.view', 'members.invite',
            'settings.view',
            'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign', 'shipments.view_own',
            'support.view', 'support.create',
        ])->pluck('id')->toArray();

        $dispatcherRole->permissions()->sync($dispatcherPerms);

        // Driver: field_worker archetype, has shipments.view_own only
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'archetype' => 'field_worker',
            'is_administrative' => false,
        ]);

        $driverPerms = CompanyPermission::whereIn('key', [
            'theme.view', 'theme.manage',
            'shipments.view_own', 'shipments.manage_status',
        ])->pluck('id')->toArray();

        $driverRole->permissions()->sync($driverPerms);

        // Users + Memberships
        $this->owner = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->dispatcher = User::factory()->create();
        $this->driver = User::factory()->create();

        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company->memberships()->create(['user_id' => $this->manager->id, 'role' => 'user', 'company_role_id' => $managerRole->id]);
        $this->company->memberships()->create(['user_id' => $this->dispatcher->id, 'role' => 'user', 'company_role_id' => $dispatcherRole->id]);
        $this->company->memberships()->create(['user_id' => $this->driver->id, 'role' => 'user', 'company_role_id' => $driverRole->id]);
    }

    private function actAs(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // REGISTRATION — 8 widgets exist in registry
    // ═══════════════════════════════════════════════════════

    public function test_all_8_operations_widgets_registered(): void
    {
        $allKeys = collect(DashboardWidgetRegistry::all())->map(fn ($w) => $w->key())->all();

        $expectedKeys = [
            'shipments.today', 'shipments.in_transit', 'shipments.late',
            'shipments.unassigned', 'drivers.active',
            'deliveries.my_today', 'deliveries.next', 'deliveries.completed_today',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertContains($key, $allKeys, "Widget '{$key}' must be registered.");
        }
    }

    public function test_operations_widgets_have_correct_module(): void
    {
        $operationsKeys = [
            'shipments.today', 'shipments.in_transit', 'shipments.late',
            'shipments.unassigned', 'drivers.active',
            'deliveries.my_today', 'deliveries.next', 'deliveries.completed_today',
        ];

        foreach ($operationsKeys as $key) {
            $widget = DashboardWidgetRegistry::find($key);
            $this->assertNotNull($widget, "Widget '{$key}' must exist.");
            $this->assertEquals('logistics_shipments', $widget->module(), "Widget '{$key}' must belong to logistics_shipments module.");
            $this->assertEquals('company', $widget->audience(), "Widget '{$key}' must be company audience.");
            $this->assertEquals('server', $widget->resolution(), "Widget '{$key}' must be server-resolved.");
        }
    }

    // ═══════════════════════════════════════════════════════
    // CATALOG — widgets visible with logistics module active
    // ═══════════════════════════════════════════════════════

    public function test_company_catalog_includes_operations_widgets_when_module_active(): void
    {
        $catalog = DashboardWidgetRegistry::catalogForCompany($this->company);
        $keys = collect($catalog)->map(fn ($w) => $w->key())->all();

        // 7 base + 8 operations = 15 company-audience widgets
        $companyWidgets = array_filter($catalog, fn ($w) => $w->audience() === 'company');
        $this->assertCount(15, $companyWidgets, 'Company catalog must include 15 widgets (7 base + 8 operations) when logistics module is active.');

        $this->assertContains('shipments.today', $keys);
        $this->assertContains('deliveries.my_today', $keys);
    }

    // ═══════════════════════════════════════════════════════
    // CATALOG PER ROLE — archetype + permission filtering
    // ═══════════════════════════════════════════════════════

    public function test_owner_sees_all_15_company_widgets_in_catalog_api(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/dashboard/widgets/catalog');
        $response->assertOk();

        $widgets = $response->json('widgets');
        $this->assertCount(15, $widgets, 'Owner (bypass) sees all 15 company widgets.');
    }

    public function test_manager_catalog_includes_shipment_widgets(): void
    {
        $managerPerms = ['theme.view', 'theme.manage', 'members.view', 'members.invite', 'members.manage',
            'members.credentials', 'members.sensitive_read', 'settings.view', 'settings.manage',
            'roles.view', 'roles.manage', 'billing.manage', 'shipments.view', 'shipments.create',
            'shipments.manage_status', 'shipments.assign', 'support.view', 'support.create'];

        $filtered = DashboardCatalogService::forArchetype($this->company, 'management', $managerPerms, false);
        $keys = collect($filtered)->map(fn ($w) => $w->key())->all();

        // Manager (management archetype) sees shipment KPIs + drivers.active
        $this->assertContains('shipments.today', $keys);
        $this->assertContains('shipments.in_transit', $keys);
        $this->assertContains('shipments.late', $keys);
        $this->assertContains('drivers.active', $keys);

        // Manager does NOT see delivery widgets (field_worker archetype)
        $this->assertNotContains('deliveries.my_today', $keys);
        $this->assertNotContains('deliveries.next', $keys);
        $this->assertNotContains('deliveries.completed_today', $keys);
    }

    public function test_dispatcher_catalog_includes_shipment_and_unassigned(): void
    {
        $dispatcherPerms = ['theme.view', 'theme.manage', 'members.view', 'members.invite',
            'settings.view', 'shipments.view', 'shipments.create', 'shipments.manage_status',
            'shipments.assign', 'shipments.view_own', 'support.view', 'support.create'];

        $filtered = DashboardCatalogService::forArchetype($this->company, 'operations_center', $dispatcherPerms, false);
        $keys = collect($filtered)->map(fn ($w) => $w->key())->all();

        // Dispatcher (operations_center) sees all 5 shipment widgets
        $this->assertContains('shipments.today', $keys);
        $this->assertContains('shipments.unassigned', $keys);
        $this->assertContains('drivers.active', $keys);

        // Dispatcher does NOT see delivery widgets (field_worker archetype)
        $this->assertNotContains('deliveries.my_today', $keys);
    }

    public function test_driver_catalog_includes_only_delivery_widgets(): void
    {
        $driverPerms = ['theme.view', 'theme.manage', 'shipments.view_own', 'shipments.manage_status'];

        $filtered = DashboardCatalogService::forArchetype($this->company, 'field_worker', $driverPerms, false);
        $keys = collect($filtered)->map(fn ($w) => $w->key())->all();

        // Driver (field_worker) sees delivery widgets
        $this->assertContains('deliveries.my_today', $keys);
        $this->assertContains('deliveries.next', $keys);
        $this->assertContains('deliveries.completed_today', $keys);

        // Driver does NOT see shipment management widgets
        $this->assertNotContains('shipments.today', $keys);
        $this->assertNotContains('shipments.unassigned', $keys);
        $this->assertNotContains('drivers.active', $keys);
    }

    // ═══════════════════════════════════════════════════════
    // SERVER RESOLVE — widgets return correct data
    // ═══════════════════════════════════════════════════════

    private function createShipment(array $attrs = []): Shipment
    {
        static $counter = 0;
        $counter++;

        return Shipment::create(array_merge([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'reference' => 'SHP-TEST-' . str_pad($counter, 4, '0', STR_PAD_LEFT),
            'status' => 'planned',
            'origin_address' => 'Origin',
            'destination_address' => 'Destination',
            'scheduled_at' => now(),
        ], $attrs));
    }

    public function test_shipments_today_resolves_correctly(): void
    {
        // 3 shipments scheduled today + 1 yesterday
        $this->createShipment(['scheduled_at' => now()->startOfDay()->addHours(10)]);
        $this->createShipment(['scheduled_at' => now()->startOfDay()->addHours(12)]);
        $this->createShipment(['scheduled_at' => now()->startOfDay()->addHours(14)]);
        $this->createShipment(['scheduled_at' => now()->subDay()]);

        $widget = DashboardWidgetRegistry::find('shipments.today');
        $result = $widget->resolve(['company_id' => $this->company->id, 'scope' => 'company']);

        $this->assertEquals(3, $result['data']['count']);
    }

    public function test_shipments_in_transit_resolves_correctly(): void
    {
        $this->createShipment(['status' => 'in_transit']);
        $this->createShipment(['status' => 'in_transit']);
        $this->createShipment(['status' => 'delivered']);

        $widget = DashboardWidgetRegistry::find('shipments.in_transit');
        $result = $widget->resolve(['company_id' => $this->company->id, 'scope' => 'company']);

        $this->assertEquals(2, $result['data']['count']);
    }

    public function test_shipments_late_resolves_correctly(): void
    {
        // Late: in_transit + scheduled_at in the past
        $this->createShipment(['status' => 'in_transit', 'scheduled_at' => now()->subHours(2)]);
        // Not late: in_transit + scheduled_at in the future
        $this->createShipment(['status' => 'in_transit', 'scheduled_at' => now()->addHours(2)]);

        $widget = DashboardWidgetRegistry::find('shipments.late');
        $result = $widget->resolve(['company_id' => $this->company->id, 'scope' => 'company']);

        $this->assertEquals(1, $result['data']['count']);
    }

    public function test_deliveries_my_today_resolves_for_authenticated_user(): void
    {
        $this->actingAs($this->driver);

        $this->createShipment(['assigned_to_user_id' => $this->driver->id, 'scheduled_at' => now()->startOfDay()->addHours(8)]);
        $this->createShipment(['assigned_to_user_id' => $this->driver->id, 'scheduled_at' => now()->startOfDay()->addHours(10)]);
        // Another driver's shipment (should not count)
        $this->createShipment(['assigned_to_user_id' => $this->dispatcher->id, 'scheduled_at' => now()->startOfDay()->addHours(8)]);

        $widget = DashboardWidgetRegistry::find('deliveries.my_today');
        $result = $widget->resolve(['company_id' => $this->company->id, 'scope' => 'company']);

        $this->assertEquals(2, $result['data']['count']);
    }

    public function test_batch_resolve_returns_operations_data(): void
    {
        $this->createShipment(['status' => 'in_transit']);
        $this->createShipment(['status' => 'in_transit']);
        $this->createShipment(['status' => 'in_transit']);

        $response = $this->actAs($this->owner)->postJson('/api/dashboard/widgets/data', [
            'widgets' => [
                ['key' => 'shipments.in_transit'],
            ],
        ]);

        $response->assertOk();
        $result = $response->json('results.0');
        $this->assertEquals('shipments.in_transit', $result['key']);
        $this->assertEquals(3, $result['data']['data']['count']);
    }
}
