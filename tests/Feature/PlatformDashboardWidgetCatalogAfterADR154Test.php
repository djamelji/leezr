<?php

namespace Tests\Feature;

use App\Core\Billing\PaymentRegistry;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widget catalog visibility after ADR-154 (platform.billing type change).
 *
 * Proves:
 * - catalogForUser() returns billing widgets when module is enabled + user has permission
 * - ModuleGate::isEnabledGlobally() falls back to manifest when no DB row exists
 * - Explicitly disabled modules hide their widgets
 * - HTTP endpoint returns widgets in correct format
 */
class PlatformDashboardWidgetCatalogAfterADR154Test extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);

        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        $this->admin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin-adr154@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ── T1: Normal path — widgets visible after sync ──

    public function test_catalog_returns_billing_widgets_after_sync(): void
    {
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        $catalog = DashboardWidgetRegistry::catalogForUser($this->admin);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        $this->assertContains('billing.revenue_trend', $keys);
        $this->assertContains('billing.refund_ratio', $keys);
        $this->assertContains('billing.ar_outstanding', $keys);
    }

    // ── T2: Manifest fallback — widgets visible even when platform_modules row is DELETED ──

    public function test_catalog_returns_billing_widgets_without_db_row(): void
    {
        // PlatformSeeder already ran ModuleRegistry::sync() in setUp.
        // Delete the platform.billing row to simulate pre-sync state.
        PlatformModule::where('key', 'platform.billing')->delete();

        // Verify the row is actually gone
        $this->assertNull(PlatformModule::where('key', 'platform.billing')->first());

        // ModuleGate::isEnabledGlobally() must fallback to manifest check
        $catalog = DashboardWidgetRegistry::catalogForUser($this->admin);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        $this->assertContains('billing.revenue_trend', $keys, 'Billing widgets must be visible via manifest fallback when no DB row exists');
    }

    // ── T3: Explicitly disabled — widgets hidden ──

    public function test_catalog_hides_widgets_when_explicitly_disabled(): void
    {
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        // Explicitly disable platform.billing
        PlatformModule::where('key', 'platform.billing')
            ->update(['is_enabled_globally' => false]);

        $catalog = DashboardWidgetRegistry::catalogForUser($this->admin);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        $billingWidgets = array_filter($keys, fn ($k) => str_starts_with($k, 'billing.'));
        $this->assertEmpty($billingWidgets, 'Billing widgets must be hidden when module is explicitly disabled');
    }

    // ── T4: Unknown module — always hidden ──

    public function test_catalog_empty_for_unknown_module(): void
    {
        $this->assertFalse(
            ModuleGate::isEnabledGlobally('nonexistent.module'),
            'Unknown modules must return false',
        );
    }

    // ── T5: isEnabledGlobally returns true for known module without DB row ──

    public function test_enabled_globally_returns_true_for_known_module_without_row(): void
    {
        // Delete the row that PlatformSeeder created
        PlatformModule::where('key', 'platform.billing')->delete();

        // Verify row is gone
        $this->assertNull(PlatformModule::where('key', 'platform.billing')->first());

        $this->assertTrue(
            ModuleGate::isEnabledGlobally('platform.billing'),
            'Known modules must default to enabled when no DB row exists (manifest fallback)',
        );
    }

    // ── T6: isEnabledGlobally returns false for unknown module without DB row ──

    public function test_enabled_globally_returns_false_for_unknown_module_without_row(): void
    {
        $this->assertFalse(
            ModuleGate::isEnabledGlobally('totally.unknown.module'),
            'Unknown modules must return false when no DB row exists',
        );
    }

    // ── T7: HTTP endpoint returns billing widgets (full pipeline test) ──

    public function test_http_catalog_endpoint_returns_billing_widgets(): void
    {
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/widgets/catalog');

        $response->assertOk();
        $response->assertJsonStructure(['widgets']);

        $widgetKeys = collect($response->json('widgets'))->pluck('key')->all();

        $this->assertContains('billing.revenue_trend', $widgetKeys);
        $this->assertContains('billing.refund_ratio', $widgetKeys);
        $this->assertContains('billing.ar_outstanding', $widgetKeys);

        // Verify response format matches frontend expectations
        $billingWidget = collect($response->json('widgets'))
            ->firstWhere('key', 'billing.revenue_trend');

        $this->assertEquals('platform.billing', $billingWidget['module']);
        $this->assertEquals('platform', $billingWidget['audience']);
        $this->assertArrayHasKey('layout', $billingWidget);
        $this->assertArrayHasKey('component', $billingWidget);
        $this->assertArrayHasKey('label_key', $billingWidget);
        $this->assertArrayHasKey('description_key', $billingWidget);
    }

    // ── T8: HTTP endpoint returns billing widgets even without DB row (manifest fallback) ──

    public function test_http_catalog_endpoint_works_without_billing_module_row(): void
    {
        // Delete billing module row to test manifest fallback end-to-end
        PlatformModule::where('key', 'platform.billing')->delete();

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/widgets/catalog');

        $response->assertOk();

        $widgetKeys = collect($response->json('widgets'))->pluck('key')->all();

        $this->assertContains('billing.revenue_trend', $widgetKeys, 'HTTP endpoint must return billing widgets via manifest fallback');
    }

    // ── T9: Non-super-admin without view_billing sees no billing widgets ──

    public function test_user_without_view_billing_sees_no_billing_widgets(): void
    {
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        $viewer = PlatformUser::create([
            'first_name' => 'Viewer',
            'last_name' => 'Test',
            'email' => 'viewer-adr154@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        // Create a restricted role WITHOUT view_billing
        $restrictedRole = PlatformRole::create(['key' => 'viewer_no_billing', 'name' => 'Viewer']);
        $viewer->roles()->attach($restrictedRole);

        $catalog = DashboardWidgetRegistry::catalogForUser($viewer);
        $keys = array_map(fn ($w) => $w->key(), $catalog);

        $billingWidgets = array_filter($keys, fn ($k) => str_starts_with($k, 'billing.'));
        $this->assertEmpty($billingWidgets, 'User without view_billing must not see billing widgets');
    }
}
