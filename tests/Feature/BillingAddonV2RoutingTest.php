<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\Listeners\AddonBillingListener;
use App\Core\Billing\Subscription;
use App\Core\Events\ModuleEnabled;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-632 (M3) — routage de l'activation de module vers V2 (BillableEvent différé) SOUS DEUX GARDES,
 * sans toucher au chemin V1. Aucune émission réelle, aucune collecte.
 */
class BillingAddonV2RoutingTest extends TestCase
{
    use RefreshDatabase;

    private string $moduleKey = 'logistics_tracking';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Jobdomains\JobdomainRegistry::sync();
        ModuleRegistry::sync();
        PlanRegistry::sync();
        PlatformModule::where('key', $this->moduleKey)->update([
            'addon_pricing' => ['pricing_model' => 'flat', 'pricing_metric' => 'none', 'pricing_params' => ['price_monthly' => 29]],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'V2 Co', 'slug' => 'v2-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c, array $over = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => 1, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => '2026-10-01 00:00:00', 'current_period_end' => '2026-10-31 23:59:59',
        ], $over));
    }

    private function fire(Company $c): void
    {
        (new AddonBillingListener)->handle(new ModuleEnabled($c, $this->moduleKey));
    }

    public function test_flag_off_keeps_v1_behavior(): void
    {
        config(['billing.consolidated.route_activations' => false]);
        $c = $this->company();
        $this->sub($c, ['v2_effective_at' => now()->subDay()]); // éligible mais flag OFF
        $this->fire($c);
        $this->assertSame(0, BillableEvent::count(), 'flag OFF → aucun event V2');
        $this->assertGreaterThan(0, Invoice::where('company_id', $c->id)->count(), 'V1 : document créé');
    }

    public function test_eligible_but_flag_on_yet_not_eligible_stays_v1(): void
    {
        config(['billing.consolidated.route_activations' => true]);
        $c = $this->company();
        $this->sub($c, ['v2_effective_at' => null]); // non éligible
        $this->fire($c);
        $this->assertSame(0, BillableEvent::count());
        $this->assertGreaterThan(0, Invoice::where('company_id', $c->id)->count(), 'non éligible → V1');
    }

    public function test_v2_eligible_records_event_and_creates_no_document(): void
    {
        config(['billing.consolidated.route_activations' => true]);
        Carbon::setTestNow('2026-10-16 10:00:00');
        $c = $this->company();
        $this->sub($c, ['v2_effective_at' => '2026-10-01 00:00:00', 'commercial_policy_version' => 'v2.0']);
        $this->fire($c);

        $this->assertSame(0, Invoice::where('company_id', $c->id)->count(), 'V2 : aucune facture');
        $this->assertSame(0, Invoice::whereNotNull('annexe_suffix')->count(), 'V2 : aucune annexe');
        $this->assertSame(1, BillableEvent::count());

        $ev = BillableEvent::first();
        $this->assertSame('module_activation', $ev->type);
        $this->assertSame('eligible', $ev->status);
        $this->assertSame(16, $ev->proration_num);
        $this->assertSame(31, $ev->proration_den);
        $this->assertSame(1496, $ev->amount_ht_cents, 'floor(16/31 × 2900) = 1496');
        $this->assertSame('v2.0', $ev->commercial_policy_version);
    }

    public function test_provisional_records_event_without_invoice(): void
    {
        config(['billing.consolidated.route_activations' => true]);
        Carbon::setTestNow('2026-10-16 10:00:00');
        $c = $this->company();
        $this->sub($c, ['plan_price_source' => 'provisional', 'v2_effective_at' => '2026-10-01 00:00:00']);
        $this->fire($c);
        $this->assertSame(1, BillableEvent::count(), 'provisional : event accumulé (activer/accumuler OK)');
        $this->assertSame(0, Invoice::where('company_id', $c->id)->count(), 'provisional : aucune émission');
    }

    public function test_double_delivery_is_idempotent(): void
    {
        config(['billing.consolidated.route_activations' => true]);
        Carbon::setTestNow('2026-10-16 10:00:00');
        $c = $this->company();
        $this->sub($c, ['v2_effective_at' => '2026-10-01 00:00:00']);
        $this->fire($c);
        $this->fire($c); // double livraison du listener
        $this->assertSame(1, BillableEvent::count(), 'rejeu → un seul event');
    }

    public function test_immediate_policy_falls_back_to_v1(): void
    {
        config(['billing.consolidated.route_activations' => true]);
        Carbon::setTestNow('2026-10-16 10:00:00');
        $c = $this->company();
        $this->sub($c, ['v2_effective_at' => '2026-10-01 00:00:00', 'metadata' => ['billing_mode' => 'immediate']]);
        $this->fire($c);
        $this->assertSame(0, BillableEvent::count(), 'immédiat non supporté V2 → pas d\'event');
        $this->assertGreaterThan(0, Invoice::where('company_id', $c->id)->count(), 'maintien V1 (facture immédiate)');
    }

    public function test_multi_tenant_isolation(): void
    {
        config(['billing.consolidated.route_activations' => true]);
        Carbon::setTestNow('2026-10-16 10:00:00');
        $a = $this->company();
        $this->sub($a, ['v2_effective_at' => '2026-10-01 00:00:00']);
        $this->fire($a);
        $this->assertSame(1, BillableEvent::where('company_id', $a->id)->count());
        $this->assertSame(0, BillableEvent::where('company_id', '!=', $a->id)->count());
    }
}
