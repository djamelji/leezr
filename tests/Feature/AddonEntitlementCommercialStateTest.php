<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Dunning\DunningTransitioner;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-C (ADR-734) — Addon entitlement depends on a valid commercial state.
 *
 * Gate 5 of EntitlementResolver must no longer grant a paid feature on the mere
 * presence of addon_pricing. The effective right is coupled to the state of the
 * CompanyAddonSubscription, with a conservative rule that preserves legitimate
 * grants (admin / legacy / free exceptions = no commercial record at all).
 */
class AddonEntitlementCommercialStateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $moduleKey = 'logistics_shipments';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $jobdomain = Jobdomain::updateOrCreate(
            ['key' => 'logistique'],
            [
                'label' => 'Logistique',
                'is_active' => true,
                // Module NOT in defaults → only reachable via addon (Gate 5).
                'default_modules' => [],
                'allow_custom_fields' => true,
            ],
        );

        $this->company = Company::create([
            'name' => 'Addon State Co',
            'slug' => 'addon-state-co',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->jobdomains()->sync([$jobdomain->id]);
        $this->company->unsetRelation('jobdomain');

        // Make the module addon-priced so Gate 5 is the deciding gate.
        PlatformModule::where('key', $this->moduleKey)->update([
            'addon_pricing' => json_encode(['pricing_model' => 'flat', 'pricing_params' => ['price_monthly' => 50]]),
        ]);
    }

    private function entitlement(): array
    {
        $this->company->unsetRelation('jobdomain');

        return EntitlementResolver::check($this->company->fresh(), $this->moduleKey);
    }

    private function addon(array $overrides = []): CompanyAddonSubscription
    {
        return CompanyAddonSubscription::create(array_merge([
            'company_id' => $this->company->id,
            'module_key' => $this->moduleKey,
            'interval' => 'monthly',
            'amount_cents' => 5000,
            'currency' => 'EUR',
            'activated_at' => now()->subMonth(),
            'deactivated_at' => null,
        ], $overrides));
    }

    // ── 1. No commercial record → preserved (admin / legacy / free exception) ──
    public function test_addon_priced_module_without_subscription_stays_entitled(): void
    {
        $result = $this->entitlement();

        $this->assertTrue($result['entitled'], 'addon_pricing + no record → preserved');
        $this->assertSame('addon', $result['source']);
    }

    // ── 2. Active subscription → entitled ──
    public function test_active_addon_subscription_is_entitled(): void
    {
        $this->addon();

        $result = $this->entitlement();

        $this->assertTrue($result['entitled']);
        $this->assertSame('addon', $result['source']);
    }

    // ── 3. Only a deactivated subscription → right is CUT ──
    public function test_deactivated_addon_subscription_loses_entitlement(): void
    {
        $this->addon(['deactivated_at' => now()->subDay()]);

        $result = $this->entitlement();

        $this->assertFalse($result['entitled'], 'deactivated addon no longer grants the paid feature');
        $this->assertSame('addon_inactive', $result['reason']);
    }

    // ── 4. Future deactivation (still in paid period) → remains entitled ──
    public function test_future_deactivation_remains_entitled_until_period_end(): void
    {
        $this->addon(['deactivated_at' => now()->addWeek()]);

        $result = $this->entitlement();

        $this->assertTrue($result['entitled'], 'scheduled end in the future still covers the period');
    }

    // ── 5. Dunning suspension deactivates active addons → entitlement cut ──
    public function test_dunning_suspension_deactivates_active_addon_and_cuts_right(): void
    {
        $this->addon();
        $this->assertTrue($this->entitlement()['entitled']);

        $policy = PlatformBillingPolicy::firstOrCreate([], [
            'grace_period_days' => 3,
            'max_retry_attempts' => 3,
            'retry_intervals_days' => [1, 3, 7],
            'failure_action' => 'suspend',
        ]);

        DunningTransitioner::applyFailureAction($this->company->fresh(), $policy);

        // Addon subscription deactivated...
        $this->assertFalse(
            CompanyAddonSubscription::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->active()->exists(),
            'suspension must deactivate active addon subscriptions'
        );

        // ...and the right is cut.
        $this->assertFalse($this->entitlement()['entitled']);
    }
}
