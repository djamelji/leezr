<?php

namespace Tests\Unit;

use App\Core\Billing\PaymentRegistry;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-149 D4e.3: Widget registry boot scanning + catalogForCompany filtering.
 */
class WidgetRegistryScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();
    }

    public function test_boot_discovers_widgets_via_scan(): void
    {
        $all = DashboardWidgetRegistry::all();

        $this->assertNotEmpty($all, 'Registry should discover widgets via widgets.php scanning.');

        $keys = array_map(fn ($w) => $w->key(), $all);
        $this->assertContains('billing.revenue_trend', $keys);
        $this->assertContains('billing.refund_ratio', $keys);
        $this->assertContains('billing.ar_outstanding', $keys);
    }

    public function test_widgets_use_system_module_key(): void
    {
        $widget = DashboardWidgetRegistry::find('billing.revenue_trend');

        $this->assertNotNull($widget);
        $this->assertEquals('platform.billing', $widget->module());
    }

    public function test_widgets_have_v2_fields(): void
    {
        $widget = DashboardWidgetRegistry::find('billing.revenue_trend');

        $this->assertNotNull($widget);
        $this->assertIsArray($widget->layout());
        $this->assertArrayHasKey('default_w', $widget->layout());
        $this->assertArrayHasKey('min_w', $widget->layout());
        $this->assertIsString($widget->category());
        $this->assertIsArray($widget->tags());
        $this->assertIsString($widget->component());
    }

    public function test_catalog_for_company_excludes_platform_audience_widgets(): void
    {
        JobdomainRegistry::sync();

        $company = Company::create(['name' => 'Widget Co', 'slug' => 'widget-co', 'jobdomain_key' => 'logistique']);
        $jobdomain = Jobdomain::where('key', 'logistique')->first();
        $company->jobdomains()->attach($jobdomain->id);

        // Billing widgets are audience='platform' → must NOT appear in company catalog
        $catalog = DashboardWidgetRegistry::catalogForCompany($company);
        $this->assertEmpty($catalog, 'Platform-audience widgets must not appear in company catalog.');
    }

    public function test_widgets_have_audience_field(): void
    {
        foreach (DashboardWidgetRegistry::all() as $w) {
            $this->assertContains(
                $w->audience(),
                ['platform', 'company', 'both'],
                "Widget {$w->key()} must have a valid audience."
            );
        }
    }
}
