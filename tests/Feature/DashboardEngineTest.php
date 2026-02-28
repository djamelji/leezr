<?php

namespace Tests\Feature;

use App\Core\Billing\LedgerEntry;
use App\Core\Billing\PaymentRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-148 D4e.2: Dashboard Engine tests.
 */
class DashboardEngineTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;
    private PlatformUser $viewer;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        // Super admin (has view_billing)
        $this->admin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        // Viewer without view_billing
        $this->viewer = PlatformUser::create([
            'first_name' => 'Viewer',
            'last_name' => 'Test',
            'email' => 'viewer@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        // Company with ledger data
        $this->company = Company::create([
            'name' => 'Engine Co',
            'slug' => 'engine-co',
            'plan_key' => 'pro',
        ]);

        $corrId = (string) Str::uuid();

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'REVENUE',
            'debit' => 0,
            'credit' => 1000.00,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => $corrId,
            'recorded_at' => now()->subDays(5),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'AR',
            'debit' => 1000.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => $corrId,
            'recorded_at' => now()->subDays(5),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'payment_received',
            'account_code' => 'AR',
            'debit' => 0,
            'credit' => 400.00,
            'currency' => 'EUR',
            'reference_type' => 'payment',
            'reference_id' => 1,
            'correlation_id' => (string) Str::uuid(),
            'recorded_at' => now()->subDays(3),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'refund_issued',
            'account_code' => 'REFUND',
            'debit' => 100.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'credit_note',
            'reference_id' => 1,
            'correlation_id' => (string) Str::uuid(),
            'recorded_at' => now()->subDays(2),
        ]);
    }

    // ── C1: catalog requires auth ──

    public function test_catalog_requires_auth(): void
    {
        $response = $this->getJson('/api/platform/dashboard/widgets/catalog');

        $response->assertStatus(401);
    }

    // ── C2: catalog filters by permission ──

    public function test_catalog_filters_by_permission(): void
    {
        // Admin sees billing widgets
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/widgets/catalog');

        $response->assertOk();
        $this->assertCount(12, $response->json('widgets'));

        // Viewer without view_billing sees 0
        $response = $this->actingAs($this->viewer, 'platform')
            ->getJson('/api/platform/dashboard/widgets/catalog');

        $response->assertOk();
        $this->assertCount(0, $response->json('widgets'));
    }

    // ── C3: catalog returns widget structure ──

    public function test_catalog_returns_widget_structure(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/widgets/catalog');

        $response->assertOk()
            ->assertJsonStructure([
                'widgets' => [
                    ['key', 'module', 'label_key', 'description_key', 'scope', 'default_config', 'layout', 'category', 'tags', 'component'],
                ],
            ]);

        $widget = collect($response->json('widgets'))->firstWhere('key', 'billing.revenue_trend');
        $this->assertNotNull($widget);
        $this->assertEquals('platform.billing', $widget['module']);
        $this->assertEquals('both', $widget['scope']);
        $this->assertEquals('billing', $widget['category']);
        $this->assertEquals('BillingRevenueTrend', $widget['component']);
    }

    // ── C4: batch resolve global scope ──

    public function test_batch_resolve_global_scope(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', [
                'widgets' => [
                    ['key' => 'billing.revenue_trend', 'scope' => 'global', 'period' => '30d'],
                    ['key' => 'billing.refund_ratio', 'scope' => 'global', 'period' => '30d'],
                    ['key' => 'billing.ar_outstanding', 'scope' => 'global'],
                ],
            ]);

        $response->assertOk();

        $results = $response->json('results');
        $this->assertCount(3, $results);

        // All should have data (no errors)
        foreach ($results as $result) {
            $this->assertArrayHasKey('data', $result, "Widget {$result['key']} has error: " . ($result['error'] ?? ''));
            $this->assertEquals('global', $result['data']['scope']);
        }
    }

    // ── C5: batch resolve company scope ──

    public function test_batch_resolve_company_scope(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', [
                'widgets' => [
                    [
                        'key' => 'billing.revenue_trend',
                        'scope' => 'company',
                        'company_id' => $this->company->id,
                        'period' => '30d',
                    ],
                ],
            ]);

        $response->assertOk();
        $result = $response->json('results.0');
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('company', $result['data']['scope']);
        $this->assertEquals('EUR', $result['data']['currency']);
    }

    // ── C6: batch resolve unknown widget ──

    public function test_batch_resolve_unknown_widget(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', [
                'widgets' => [
                    ['key' => 'unknown.widget', 'scope' => 'global'],
                ],
            ]);

        $response->assertOk();
        $this->assertEquals('not_found', $response->json('results.0.error'));
    }

    // ── C7: company scope requires company_id ──

    public function test_batch_resolve_company_scope_requires_company_id(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', [
                'widgets' => [
                    ['key' => 'billing.revenue_trend', 'scope' => 'company'],
                ],
            ]);

        $response->assertOk();
        $this->assertEquals('company_id_required', $response->json('results.0.error'));
    }

    // ── C8: layout get returns default ──

    public function test_layout_get_returns_default(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/layout');

        $response->assertOk();

        $layout = $response->json('layout');
        $this->assertIsArray($layout);
        $this->assertCount(3, $layout);
        $this->assertEquals('billing.revenue_trend', $layout[0]['key']);
    }

    // ── C9: layout put saves and get returns ──

    public function test_layout_put_saves_and_get_returns(): void
    {
        $customLayout = [
            ['key' => 'billing.ar_outstanding', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2, 'scope' => 'global', 'config' => []],
            ['key' => 'billing.refund_ratio', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => ['period' => '7d']],
        ];

        $response = $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/dashboard/layout', ['layout' => $customLayout]);

        $response->assertOk();
        $this->assertCount(2, $response->json('layout'));

        // GET should return the saved layout
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/layout');

        $response->assertOk();
        $this->assertCount(2, $response->json('layout'));
        $this->assertEquals('billing.ar_outstanding', $response->json('layout.0.key'));
    }

    // ── C10: layout is per-user ──

    public function test_layout_is_per_user(): void
    {
        // Give viewer a role so they can access the endpoint
        $viewerRole = PlatformRole::create(['key' => 'dashboard_viewer', 'name' => 'Dashboard Viewer']);
        $this->viewer->roles()->attach($viewerRole);

        // Admin saves a layout
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/dashboard/layout', [
                'layout' => [
                    ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'global', 'config' => []],
                ],
            ]);

        // Viewer saves a different layout
        $this->actingAs($this->viewer, 'platform')
            ->putJson('/api/platform/dashboard/layout', [
                'layout' => [
                    ['key' => 'billing.ar_outstanding', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2, 'scope' => 'global', 'config' => []],
                ],
            ]);

        // Verify independent
        $adminLayout = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/dashboard/layout')
            ->json('layout');

        $viewerLayout = $this->actingAs($this->viewer, 'platform')
            ->getJson('/api/platform/dashboard/layout')
            ->json('layout');

        $this->assertCount(1, $adminLayout);
        $this->assertEquals('billing.revenue_trend', $adminLayout[0]['key']);

        $this->assertCount(1, $viewerLayout);
        $this->assertEquals('billing.ar_outstanding', $viewerLayout[0]['key']);
    }
}
