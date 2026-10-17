<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Database\Seeders\ModuleProductConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 13 — DEV/PROD divergence root cause: the sellable add-on config (is_sellable +
 * addon_pricing) for Workforce/logistics lived only in DevSeeder. On PROD it never ran,
 * so EntitlementResolver returned `not_available` → display_state `contact_sales` → the
 * module was UNSELLABLE/UNACTIVATABLE (while staging's DevSeeder force-activated it,
 * masking the gap). ModuleProductConfigSeeder (now called by SystemSeeder) fixes it.
 */
class WorkforceModuleActivationJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $jd = Jobdomain::updateOrCreate(['key' => 'logistique'], [
            'label' => 'Logistique', 'is_active' => true,
            'default_modules' => ['logistics_shipments', 'logistics_tracking', 'logistics_fleet', 'logistics_analytics'],
            'allow_custom_fields' => true,
        ]);
        $c->jobdomains()->sync([$jd->id]);

        return $c;
    }

    private function workforceState(Company $c): array
    {
        return collect(ModuleCatalogReadModel::forCompany($c))->keyBy('key')->get('workforce') ?? [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
    }

    public function test_without_product_config_workforce_is_not_sellable_the_prod_bug(): void
    {
        // Reproduces PROD before the fix: no addon_pricing → not sellable.
        $wf = $this->workforceState($this->company('prod-like'));

        $this->assertNotEmpty($wf, 'Workforce must be visible (compatible jobdomain), not hidden.');
        $this->assertFalse((bool) ($wf['addon_pricing'] ?? false), 'No pricing configured → the broken state.');
        $this->assertSame('contact_sales', $wf['display_state'], 'Unconfigured add-on falls through to contact_sales (the PROD bug).');
    }

    public function test_product_config_makes_workforce_available_on_pro(): void
    {
        // The fix: run the (now production-safe) product config seeder.
        $this->seed(ModuleProductConfigSeeder::class);

        $wf = $this->workforceState($this->company('fixed'));

        $this->assertTrue((bool) PlatformModule::where('key', 'workforce')->value('is_sellable'));
        $this->assertNotEmpty($wf['addon_pricing'], 'Workforce now has addon pricing.');
        $this->assertTrue($wf['is_entitled'], 'Workforce is now entitled for a pro company (included seats).');
        // contact_sales (broken/unavailable) → available (entitled & activatable).
        $this->assertSame('available', $wf['display_state']);
    }

    public function test_seeder_does_not_overwrite_an_existing_commercial_config(): void
    {
        // Simulate an admin-customized price already in place.
        $custom = ['pricing_model' => 'flat', 'pricing_metric' => 'none', 'pricing_params' => ['price_monthly' => 999]];
        PlatformModule::where('key', 'workforce')->update(['addon_pricing' => $custom, 'is_sellable' => true]);

        $this->seed(ModuleProductConfigSeeder::class);

        // Must NOT be clobbered by the default config.
        $this->assertSame(999, PlatformModule::where('key', 'workforce')->value('addon_pricing')['pricing_params']['price_monthly']);
    }

    public function test_seeder_is_idempotent_on_repeated_runs(): void
    {
        $this->seed(ModuleProductConfigSeeder::class);
        $after1 = PlatformModule::where('key', 'workforce')->value('addon_pricing');
        $this->seed(ModuleProductConfigSeeder::class);
        $after2 = PlatformModule::where('key', 'workforce')->value('addon_pricing');

        $this->assertEquals($after1, $after2, 'Re-running the seeder must not change an initialized config.');
    }

    public function test_system_seeder_includes_product_config_so_prod_is_consistent(): void
    {
        // Guard: the product config is wired into SystemSeeder (runs on prod), not dev-only.
        $this->assertStringContainsString(
            'ModuleProductConfigSeeder',
            file_get_contents(base_path('database/seeders/SystemSeeder.php')),
            'SystemSeeder must seed the module product config so PROD gets it too.'
        );
    }
}
