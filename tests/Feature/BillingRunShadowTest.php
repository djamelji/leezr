<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillingRun;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyWallet;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-632 (M2) — BillingRun shadow : simule une facture consolidée complète SANS effet de bord.
 */
class BillingRunShadowTest extends TestCase
{
    use RefreshDatabase;

    private BillingRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        Plan::updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_monthly' => 2900, 'price_yearly' => 29000, 'is_active' => true]);
        $this->run = new BillingRun;
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Run Co', 'slug' => 'run-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c, string $source = 'subscribed', int $price = 2900): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => $price, 'plan_price_source' => $source,
            'current_period_start' => '2026-11-01 00:00:00', 'current_period_end' => '2026-11-30 23:59:59',
        ]);
    }

    private function addon(Company $c, string $key, int $cents): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $c->id, 'module_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR',
            'interval' => 'monthly', 'activated_at' => now()->subDay(), 'deactivated_at' => null,
        ]);
    }

    public function test_plan_only(): void
    {
        $c = $this->company();
        $sim = $this->run->simulate($this->sub($c));
        $this->assertCount(1, $sim->lines);
        $this->assertSame('plan', $sim->lines[0]->type);
        $this->assertSame(2900, $sim->subtotalHtCents);
        $this->assertSame(580, $sim->taxAmountCents); // 20% FR
        $this->assertSame(3480, $sim->totalTtcCents);
        $this->assertFalse($sim->eligibleForEmission);
    }

    public function test_plan_plus_one_module(): void
    {
        $c = $this->company();
        $this->addon($c, 'workforce_planning', 2900);
        $sim = $this->run->simulate($this->sub($c));
        $this->assertCount(2, $sim->lines);
        $this->assertSame(5800, $sim->subtotalHtCents);
        $this->assertSame(6960, $sim->totalTtcCents);
    }

    public function test_plan_plus_134_modules_is_one_simulation(): void
    {
        $c = $this->company();
        for ($i = 1; $i <= 134; $i++) {
            $this->addon($c, "mod_{$i}", 100);
        }
        $sim = $this->run->simulate($this->sub($c));
        $this->assertCount(135, $sim->lines, '1 plan + 134 modules = 135 lignes, 1 simulation');
        $this->assertSame(2900 + 134 * 100, $sim->subtotalHtCents);
    }

    public function test_prior_prorata_events_are_included(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        BillableEvent::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'module_activation',
            'module_key' => 'workforce_planning', 'service_period_start' => '2026-10-16 00:00:00',
            'service_period_end' => '2026-11-01 00:00:00', 'price_snapshot_cents' => 2900, 'proration_num' => 16,
            'proration_den' => 31, 'amount_ht_cents' => 1496, 'status' => 'eligible', 'idempotency_key' => 'ev-prior-1',
        ]);
        $sim = $this->run->simulate($sub);
        $this->assertSame(1, $sim->eventsRead);
        $prorata = collect($sim->lines)->firstWhere('sourceEventId', '!=', null);
        $this->assertNotNull($prorata);
        $this->assertSame(1496, $prorata->amountHtCents);
        $this->assertSame(2900 + 1496, $sim->subtotalHtCents);
    }

    public function test_provisional_price_holds_the_cycle(): void
    {
        $c = $this->company();
        $sim = $this->run->simulate($this->sub($c, 'provisional'));
        $this->assertContains('cycle_hold', $sim->holds);
        $this->assertContains('pricing_review_required', $sim->holds);
        $this->assertFalse($sim->eligibleForEmission);
    }

    public function test_ambiguous_event_period_is_excluded_from_total(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        BillableEvent::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'module_activation',
            'module_key' => 'workforce_x', 'service_period_start' => null, 'service_period_end' => null,
            'amount_ht_cents' => 9999, 'status' => 'eligible', 'idempotency_key' => 'ev-amb',
        ]);
        $sim = $this->run->simulate($sub);
        $this->assertContains('ambiguous_period_event:'.BillableEvent::first()->id, $sim->warnings);
        $this->assertSame(2900, $sim->subtotalHtCents, 'montant ambigu exclu — aucun montant fictif');
    }

    public function test_only_eligible_events_are_read(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        foreach (['reserved', 'invoiced', 'cancelled'] as $st) {
            BillableEvent::create([
                'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'adjustment',
                'service_period_start' => '2026-10-01 00:00:00', 'service_period_end' => '2026-10-15 00:00:00',
                'amount_ht_cents' => 500, 'status' => $st, 'idempotency_key' => "ev-{$st}",
                'invoice_line_id' => $st === 'invoiced' ? 777 : null,
            ]);
        }
        $sim = $this->run->simulate($sub);
        $this->assertSame(0, $sim->eventsRead, 'reserved/invoiced/cancelled non lus');
        $this->assertSame(2900, $sim->subtotalHtCents);
    }

    public function test_repeated_simulation_is_identical(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $a = $this->run->simulate($sub);
        $b = $this->run->simulate($sub);
        $this->assertSame($a->runIdentity, $b->runIdentity);
        $this->assertSame($a->subtotalHtCents, $b->subtotalHtCents);
        $this->assertSame($a->totalTtcCents, $b->totalTtcCents);
    }

    public function test_no_side_effects_at_all(): void
    {
        $c = $this->company();
        $this->addon($c, 'workforce_planning', 2900);
        $sub = $this->sub($c);
        BillableEvent::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'module_activation',
            'module_key' => 'm', 'service_period_start' => '2026-10-16 00:00:00', 'service_period_end' => '2026-11-01 00:00:00',
            'amount_ht_cents' => 1000, 'status' => 'eligible', 'idempotency_key' => 'ev-se',
        ]);

        $before = [
            'invoices' => Invoice::count(),
            'events' => BillableEvent::count(),
            'eligible' => BillableEvent::where('status', 'eligible')->count(),
            'ledger' => LedgerEntry::count(),
            'wallets' => CompanyWallet::count(),
            'sub_period_end' => $sub->current_period_end->toIso8601String(),
        ];

        $this->run->simulate($sub); // company has NO wallet → ensureWallet would create one, but rollback neutralizes it

        $this->assertSame($before['invoices'], Invoice::count(), 'aucune facture créée');
        $this->assertSame($before['events'], BillableEvent::count(), 'aucun event créé');
        $this->assertSame($before['eligible'], BillableEvent::where('status', 'eligible')->count(), 'statuts events inchangés');
        $this->assertSame($before['ledger'], LedgerEntry::count(), 'ledger inchangé');
        $this->assertSame($before['wallets'], CompanyWallet::count(), 'aucun wallet créé (rollback shadow)');
        $this->assertSame($before['sub_period_end'], $sub->fresh()->current_period_end->toIso8601String(), 'subscription inchangée');
        $this->assertNull(BillableEvent::where('idempotency_key', 'ev-se')->first()->reserved_at, 'reserved_at jamais rempli');
    }
}
