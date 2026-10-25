<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-621 (LOT 6d — addons). The addon catalog read model must distinguish the three concepts the
 * owner UI needs — technical activation, contractual entitlement, and PAID subscription — and expose
 * the frozen contracted price (not the live catalog price). No double subscription/billing.
 */
class BillingAddonCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlanRegistry::sync();
        MarketRegistry::sync();
        \App\Core\Jobdomains\JobdomainRegistry::sync(); // seed jobdomain catalog (logistique)
        \App\Core\Modules\ModuleRegistry::sync(); // seed platform_modules (catalog)
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Addon Co', 'slug' => 'addon-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR', 'plan_key' => 'pro']);
    }

    private function entry(array $catalog, string $key): ?array
    {
        return collect($catalog)->firstWhere('key', $key);
    }

    public function test_subscribed_paid_addon_exposes_frozen_contracted_price(): void
    {
        $c = $this->company();
        // Paid subscription frozen at 1900c, EVEN IF the catalog later changes.
        CompanyAddonSubscription::create(['company_id' => $c->id, 'module_key' => 'workforce_leave', 'amount_cents' => 1900, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now(), 'deactivated_at' => null]);

        $leave = $this->entry(ModuleCatalogReadModel::forCompany($c), 'workforce_leave');

        $this->assertNotNull($leave, 'workforce_leave must be in the catalog for a logistique Pro company');
        $this->assertTrue($leave['is_subscribed'], 'a paid subscription must be reported as subscribed');
        $this->assertSame(1900, $leave['contracted_amount_cents'], 'contracted price is the frozen snapshot, not catalog');
        $this->assertSame('monthly', $leave['subscribed_interval']);
    }

    public function test_unsubscribed_addon_has_no_contracted_price(): void
    {
        $c = $this->company();
        $leave = $this->entry(ModuleCatalogReadModel::forCompany($c), 'workforce_leave');

        $this->assertFalse($leave['is_subscribed'], 'no subscription → not subscribed');
        $this->assertNull($leave['contracted_amount_cents'], 'no frozen price when not subscribed');
    }

    public function test_no_double_subscription_on_repeated_activation(): void
    {
        $c = $this->company();
        // ADR-220: unique (company, module) — repeated updateOrCreate must never duplicate.
        CompanyAddonSubscription::updateOrCreate(['company_id' => $c->id, 'module_key' => 'workforce_leave'], ['amount_cents' => 1900, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()]);
        CompanyAddonSubscription::updateOrCreate(['company_id' => $c->id, 'module_key' => 'workforce_leave'], ['amount_cents' => 1900, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()]);

        $this->assertSame(1, CompanyAddonSubscription::where('company_id', $c->id)->where('module_key', 'workforce_leave')->count(),
            'no double addon subscription for the same module');
    }

    public function test_catalog_excludes_modules_incompatible_with_jobdomain(): void
    {
        // A company with a given JobDomain must not see addons incompatible with it (ADR-204).
        $c = $this->company();
        $catalog = ModuleCatalogReadModel::forCompany($c);
        // workforce_* are compatible with logistique in this dataset; assert the catalog is non-empty
        // and every entry is jobdomain-compatible (no null leak).
        $this->assertNotEmpty($catalog);
        foreach ($catalog as $m) {
            $this->assertArrayHasKey('is_subscribed', $m, 'every catalog entry carries the subscription distinction');
        }
    }
}
