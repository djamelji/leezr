<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Modules\AdminModuleService;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Navigation\NavBuilder;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBillingModuleToggleTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ── C1: Disable → billing routes return 403 ─────────────────

    public function test_disable_billing_blocks_routes(): void
    {
        AdminModuleService::disable('platform.billing');

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/invoices');

        $response->assertStatus(403);
    }

    // ── C2: Disable → billing nav items absent ──────────────────

    public function test_disable_billing_removes_nav_items(): void
    {
        AdminModuleService::disable('platform.billing');

        $groups = NavBuilder::forAdmin(null);

        $allKeys = collect($groups)->pluck('items')->flatten(1)->pluck('key')->toArray();

        $this->assertNotContains('billing', $allKeys);
        $this->assertNotContains('payments', $allKeys);
        $this->assertNotContains('billing-settings', $allKeys);
    }

    // ── C3: Disable → billing widgets absent from catalogForUser ─

    public function test_disable_billing_removes_widgets(): void
    {
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        AdminModuleService::disable('platform.billing');

        $catalog = DashboardWidgetRegistry::catalogForUser($this->platformAdmin);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        $billingWidgets = array_filter($keys, fn ($k) => str_starts_with($k, 'billing.'));
        $this->assertEmpty($billingWidgets, 'Billing widgets must not appear when module is disabled');
    }

    // ── C4: Re-enable → all restored ────────────────────────────

    public function test_reenable_billing_restores_everything(): void
    {
        AdminModuleService::disable('platform.billing');
        AdminModuleService::enable('platform.billing');

        // Routes work
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/invoices');

        $response->assertStatus(200);

        // Nav items present
        $groups = NavBuilder::forAdmin(null);
        $allKeys = collect($groups)->pluck('items')->flatten(1)->pluck('key')->toArray();

        $this->assertContains('billing', $allKeys);

        // Widgets present
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        $catalog = DashboardWidgetRegistry::catalogForUser($this->platformAdmin);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        $billingWidgets = array_filter($keys, fn ($k) => str_starts_with($k, 'billing.'));
        $this->assertNotEmpty($billingWidgets, 'Billing widgets must reappear when module is re-enabled');
    }

    // ── C5: Platform billing widgets never in company catalog ────

    public function test_billing_widgets_never_in_company_catalog(): void
    {
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        $company = Company::create([
            'name' => 'Widget Test Co',
            'slug' => 'widget-test-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        $catalog = DashboardWidgetRegistry::catalogForCompany($company);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        // Platform-audience billing widgets must be excluded,
        // but company-audience billing widgets (plan_badge) are allowed (ADR-372)
        $platformBillingWidgets = array_filter($keys, fn ($k) => str_starts_with($k, 'billing.') && $k !== 'billing.plan_badge');
        $this->assertEmpty($platformBillingWidgets, 'Platform billing widgets must never appear in company catalog');
        $this->assertContains('billing.plan_badge', $keys, 'Company-audience billing widget plan_badge must appear in company catalog');
    }

    // ── C6: Module manifest declares type platform ──────────────

    public function test_billing_module_is_platform_type(): void
    {
        $manifest = ModuleRegistry::definitions()['platform.billing'] ?? null;

        $this->assertNotNull($manifest);
        $this->assertEquals('platform', $manifest->type);
        $this->assertEquals('admin', $manifest->scope);
        $this->assertNull($manifest->settingsRoute); // ADR-345: billing settings page removed, nav item suffices
    }

    // ── C7: Granular permissions exist after sync ───────────────

    public function test_granular_permissions_exist(): void
    {
        PlatformPermissionCatalog::sync();

        $expected = [
            'view_billing',
            'manage_billing',
            'manage_billing_providers',
            'manage_billing_policies',
            'view_billing_audit',
        ];

        foreach ($expected as $key) {
            $this->assertDatabaseHas('platform_permissions', ['key' => $key]);
        }
    }
}
