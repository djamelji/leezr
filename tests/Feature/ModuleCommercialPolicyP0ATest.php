<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleCommercialPolicy;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use Database\Seeders\ModuleProductConfigSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-732 (P0-A) — Sécurisation du catalogue commercial : un module n'est achetable QUE si l'autorité
 * ModuleCommercialPolicy l'autorise (statut 'available' + règles), jamais au seul motif qu'il a un prix.
 */
class ModuleCommercialPolicyP0ATest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private const EMPTY_MODULES = ['logistics_fleet', 'logistics_tracking', 'logistics_analytics'];

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_planning', 'logistics_shipments']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlanRegistry::sync();
        ModuleRegistry::sync();
        (new ModuleProductConfigSeeder)->run(); // remplit addon_pricing + is_sellable des addons (dont les 3 vides)
        PlatformModule::query()->update(['is_enabled_globally' => true]);
        $this->company = Company::create(['name' => 'Logi', 'slug' => 'logi-'.uniqid(), 'plan_key' => 'business', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        Model::reguard();
    }

    // 1. Module vide (coquille) = NON commercialisable MALGRÉ un prix seedé.
    public function test_empty_fictional_modules_are_not_purchasable_despite_price(): void
    {
        foreach (self::EMPTY_MODULES as $key) {
            $pm = PlatformModule::where('key', $key)->first();
            $this->assertNotNull($pm, "$key doit exister au référentiel (conservé, non supprimé)");
            $this->assertTrue((bool) $pm->is_sellable, "$key a bien un flag is_sellable (seedé)");
            $this->assertNotNull($pm->addon_pricing, "$key a bien un prix seedé");
            // ... mais l'autorité commerciale le refuse.
            $this->assertFalse(ModuleCommercialPolicy::isPurchasable($this->company, $key), "$key NON achetable");
            $this->assertSame('not_commercialisable', ModuleCommercialPolicy::blockReason($this->company, $key));
            $this->assertSame('preview', ModuleCommercialPolicy::statusFor($key));
        }
    }

    // 2. Module fonctionnel commercialisable.
    public function test_functional_module_is_purchasable(): void
    {
        $this->assertSame('available', ModuleCommercialPolicy::statusFor('workforce_planning'));
        $this->assertTrue(ModuleCommercialPolicy::isPurchasable($this->company, 'workforce_planning'));
    }

    // 3. L'endpoint d'activation/achat REFUSE un module non commercialisable (contournement frontend inutile).
    public function test_activation_engine_refuses_non_commercialisable(): void
    {
        foreach (self::EMPTY_MODULES as $key) {
            $res = ModuleActivationEngine::enable($this->company, $key);
            $this->assertFalse($res['success'], "$key : activation refusée");
            $this->assertSame(422, $res['status']);
            $this->assertSame('not_commercialisable', $res['data']['reason'] ?? null);
        }
    }

    // 4. Le catalogue ne propose AUCUN prix commandable pour un module en préparation.
    public function test_catalog_hides_price_for_preview_modules(): void
    {
        $catalog = collect(ModuleCatalogReadModel::forCompany($this->company))->keyBy('key');
        foreach (self::EMPTY_MODULES as $key) {
            $row = $catalog->get($key);
            $this->assertNotNull($row, "$key présent au catalogue (En préparation, non masqué du référentiel)");
            $this->assertSame('preview', $row['commercial_status']);
            $this->assertFalse($row['purchasable']);
            $this->assertNull($row['addon_pricing'], "$key : aucun prix commandable exposé");
            $this->assertNull($row['purchase_mode']);
        }
        // Un module réel garde son prix.
        $wp = $catalog->get('workforce_planning');
        $this->assertTrue($wp['purchasable']);
        $this->assertNotNull($wp['addon_pricing']);
    }

    // 5. Module interne / admin : jamais commercialisable côté company.
    public function test_admin_module_not_purchasable(): void
    {
        $this->assertFalse(ModuleCommercialPolicy::isPurchasable($this->company, 'platform.companies'));
        $this->assertSame('not_a_company_addon', ModuleCommercialPolicy::blockReason($this->company, 'platform.companies'));
    }

    // 6. Bêta exclu de la vente par défaut (pas de politique explicite).
    public function test_beta_excluded_from_sellable_statuses(): void
    {
        $this->assertNotContains('beta', ModuleCommercialPolicy::SELLABLE_STATUSES);
        $this->assertSame(['available'], ModuleCommercialPolicy::SELLABLE_STATUSES);
    }

    // 7. JobDomain incompatible : module réel refusé si le jobdomain ne correspond pas.
    public function test_incompatible_jobdomain_blocks_purchase(): void
    {
        Model::unguard();
        Jobdomain::create(['key' => 'autre', 'label' => 'Autre', 'default_modules' => []]);
        $other = Company::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid(), 'plan_key' => 'business', 'jobdomain_key' => 'autre', 'market_key' => 'FR']);
        Model::reguard();
        // logistics_shipments est compatible 'logistique' uniquement → refusé pour 'autre'.
        $this->assertFalse(ModuleCommercialPolicy::isPurchasable($other, 'logistics_shipments'));
    }

    // 8. INVARIANT d'intégrité : tout addon company déclaré 'available' DOIT exposer une capacité réelle.
    //    (Empêche qu'une future coquille vide soit déclarée commercialisable.)
    public function test_invariant_available_company_addons_declare_real_capabilities(): void
    {
        $violations = [];
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->scope === 'company' && $manifest->type === 'addon'
                && $manifest->commercialStatus === 'available'
                && ! ModuleCommercialPolicy::declaresRealCapability($key)) {
                $violations[] = $key;
            }
        }
        $this->assertSame([], $violations, 'Modules "available" sans aucune capacité réelle : '.implode(', ', $violations));
    }

    // 9. Historique préservé : l'autorité ne mute RIEN (pas de suppression/révocation).
    public function test_authority_does_not_mutate_data(): void
    {
        $before = PlatformModule::where('key', 'logistics_fleet')->first()->toArray();
        ModuleCommercialPolicy::isPurchasable($this->company, 'logistics_fleet');
        ModuleCatalogReadModel::forCompany($this->company);
        ModuleActivationEngine::enable($this->company, 'logistics_fleet');
        $after = PlatformModule::where('key', 'logistics_fleet')->first()->toArray();
        $this->assertSame($before['is_sellable'], $after['is_sellable'], 'is_sellable inchangé');
        $this->assertSame($before['addon_pricing'], $after['addon_pricing'], 'addon_pricing inchangé');
    }
}
