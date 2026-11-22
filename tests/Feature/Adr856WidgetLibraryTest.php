<?php

namespace Tests\Feature;

use App\Core\Billing\PaymentRegistry;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Modules\Dashboard\CompanyDashboardLayout;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-856 (Leezr 5.0 L4) — widget library : coherent size bounds for EVERY widget, KPI family bounds (a size that has
 * nothing more to show is refused), and the Company layout never STORES a widget outside the user's catalog.
 */
class Adr856WidgetLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** count-only KPI : value + context, nothing more beyond 6×2 */
    private const KPI_COUNT = ['shipments.today', 'shipments.in_transit', 'shipments.late', 'shipments.unassigned', 'drivers.active',
        'deliveries.my_today', 'workforce.timesheet_pending', 'workforce.leave_pending', 'billing.pending_dunning', 'billing.mrr',
        'billing.ar_outstanding', 'ai.docs_analyzed', 'ai.confidence', 'compliance.pending'];

    /** KPI with a breakdown : expanded view at 3 rows */
    private const KPI_BREAKDOWN = ['workforce.headcount', 'workforce.anomalies', 'planning.shifts', 'deliveries.completed_today',
        'compliance.overdue', 'billing.refund_ratio', 'ai.detections', 'ai.auto_actions', 'deliveries.next'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        CompanyPermissionCatalog::sync();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();
        JobdomainRegistry::sync();
    }

    public function test_every_widget_declares_coherent_bounds_within_the_grid(): void
    {
        $all = DashboardWidgetRegistry::all();
        $this->assertGreaterThanOrEqual(50, count($all));
        foreach ($all as $w) {
            $l = $w->layout();
            $k = $w->key();
            $this->assertGreaterThanOrEqual(3, $l['min_w'], "$k min_w ≥ grid minimum (3)");
            $this->assertGreaterThanOrEqual(2, $l['min_h'], "$k min_h ≥ 2");
            $this->assertLessThanOrEqual(12, $l['max_w'], "$k max_w ≤ 12");
            $this->assertLessThanOrEqual(8, $l['max_h'], "$k max_h ≤ grid cap (8)");
            $this->assertTrue($l['min_w'] <= $l['default_w'] && $l['default_w'] <= $l['max_w'], "$k default_w within bounds");
            $this->assertTrue($l['min_h'] <= $l['default_h'] && $l['default_h'] <= $l['max_h'], "$k default_h within bounds");
        }
    }

    public function test_kpi_family_refuses_sizes_with_nothing_more_to_show(): void
    {
        foreach (self::KPI_COUNT as $k) {
            $l = DashboardWidgetRegistry::find($k)->layout();
            $this->assertSame([6, 2], [$l['max_w'], $l['max_h']], "$k : count-only KPI stops at 6×2");
        }
        foreach (self::KPI_BREAKDOWN as $k) {
            $l = DashboardWidgetRegistry::find($k)->layout();
            $this->assertSame([6, 3], [$l['max_w'], $l['max_h']], "$k : KPI with breakdown stops at 6×3");
        }
    }

    public function test_the_hours_trend_can_shrink_to_its_kpi_presentation(): void
    {
        $l = DashboardWidgetRegistry::find('workforce.hours_trend')->layout();
        $this->assertSame([3, 2], [$l['min_w'], $l['min_h']], 'relaxed : the trend recomposes into a KPI tile when small');
    }

    public function test_company_layout_never_stores_a_widget_outside_the_catalog(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Lib Co', 'slug' => 'lib-co-856', 'jobdomain_key' => 'logistique']);
        $company->jobdomains()->attach(Jobdomain::where('key', 'logistique')->first()->id);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'core.billing', 'is_enabled_for_company' => true]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $tile = fn ($k, $x) => ['key' => $k, 'x' => $x, 'y' => 0, 'w' => 6, 'h' => 2, 'scope' => 'company', 'config' => []];

        // shipments.today belongs to a module this company has not activated → outside its catalog
        $this->actingAs($owner)->withHeaders(['X-Company-Id' => $company->id])
            ->putJson('/api/dashboard/layout', ['layout' => [$tile('billing.plan_badge', 0), $tile('shipments.today', 6)]])
            ->assertOk()->assertJsonMissing(['key' => 'shipments.today']);

        $stored = CompanyDashboardLayout::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $owner->id)->value('layout_json');
        $this->assertNotContains('shipments.today', array_column($stored, 'key'));
    }
}
