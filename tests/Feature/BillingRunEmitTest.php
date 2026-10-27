<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillingRun;
use App\Core\Billing\BillingWatermark;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Billing\ScheduledDebit;
use App\Core\Billing\Subscription;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-632 (M4) — émission consolidée transactionnelle. Capacité d'émission DÉSACTIVÉE par défaut ;
 * testée dans des transactions isolées (RefreshDatabase :memory:, rollback) — aucune facture V2
 * conservée. Aucun effet externe (paiement/SEPA/notification).
 */
class BillingRunEmitTest extends TestCase
{
    use RefreshDatabase;

    private BillingRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        JobdomainRegistry::sync();
        Plan::updateOrCreate(['key' => 'pro'], ['name' => 'Pro', 'price_monthly' => 2900, 'price_yearly' => 29000, 'is_active' => true]);
        $this->run = new BillingRun;
        config(['billing.consolidated.emit' => true]); // activé UNIQUEMENT en test
        Notification::fake();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Emit Co', 'slug' => 'emit-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c, array $over = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => 1, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => '2026-11-01 00:00:00', 'current_period_end' => '2026-11-30 23:59:59',
            'v2_effective_at' => '2026-10-01 00:00:00', 'commercial_policy_version' => 'v2.0',
        ], $over));
    }

    private function addon(Company $c, string $key, int $cents): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $c->id, 'module_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR',
            'interval' => 'monthly', 'activated_at' => now()->subDay(), 'deactivated_at' => null,
        ]);
    }

    public function test_flag_off_is_disabled(): void
    {
        config(['billing.consolidated.emit' => false]);
        $c = $this->company();
        $r = $this->run->emit($this->sub($c));
        $this->assertSame('disabled', $r->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_not_eligible_is_blocked(): void
    {
        $c = $this->company();
        $r = $this->run->emit($this->sub($c, ['v2_effective_at' => null]));
        $this->assertSame('blocked', $r->status);
        $this->assertContains('not_eligible', $r->blockedReasons);
        $this->assertSame(0, Invoice::count());
    }

    public function test_provisional_blocks_cycle(): void
    {
        $c = $this->company();
        $r = $this->run->emit($this->sub($c, ['plan_price_source' => 'provisional']));
        $this->assertSame('blocked', $r->status);
        $this->assertContains('cycle_hold', $r->blockedReasons);
        $this->assertSame(0, Invoice::count());
    }

    public function test_emits_consolidated_invoice_with_plan_modules_and_prorata(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $this->addon($c, 'workforce_planning', 2900);
        BillableEvent::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'module_activation',
            'module_key' => 'workforce_docs', 'service_period_start' => '2026-10-16 00:00:00', 'service_period_end' => '2026-11-01 00:00:00',
            'amount_ht_cents' => 1496, 'status' => 'eligible', 'idempotency_key' => 'ev-m4',
        ]);

        $r = $this->run->emit($sub);
        $this->assertTrue($r->wasEmitted());
        $inv = Invoice::find($r->invoiceId);
        $this->assertSame(2, (int) $inv->billing_model_version);
        $this->assertNotNull($inv->finalized_at);
        $this->assertSame(3, $r->linesCount, 'plan + module récurrent + prorata');
        $this->assertSame(1, $r->eventsInvoiced);
        // Réconciliation : subtotal = 2900 (plan) + 2900 (module) + 1496 (prorata) = 7296 ; TVA 20% agrégée.
        $this->assertSame(7296, $r->subtotalHtCents);
        $this->assertSame(1459, $r->taxAmountCents, 'floor(0.20 × 7296)');
        $this->assertSame(7296 + 1459, $r->totalTtcCents);
        // Event lié + invoiced.
        $this->assertSame('invoiced', BillableEvent::where('idempotency_key', 'ev-m4')->first()->status);
        // Watermarks avancés.
        $this->assertSame(2, BillingWatermark::where('subscription_id', $sub->id)->count());
        // Aucun effet externe.
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, ScheduledDebit::count());
        Notification::assertNothingSent();
    }

    public function test_replay_is_idempotent_no_duplicate(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $first = $this->run->emit($sub);
        $second = $this->run->emit($sub);
        $this->assertTrue($first->wasEmitted());
        $this->assertSame('replayed', $second->status);
        $this->assertSame($first->invoiceId, $second->invoiceId);
        $this->assertSame(1, Invoice::count(), 'aucune facture dupliquée');
    }

    public function test_unknown_watermark_blocks_emission(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        BillingWatermark::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'prestation_key' => 'plan', 'currency' => 'EUR',
            'billed_through' => null, // explicitement inconnu
        ]);
        $r = $this->run->emit($sub);
        $this->assertSame('blocked', $r->status);
        $this->assertContains('watermark_unknown:plan', $r->blockedReasons);
        $this->assertSame(0, Invoice::count());
    }

    public function test_already_covered_prestation_is_skipped(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $this->addon($c, 'workforce_planning', 2900);
        // Plan déjà couvert jusqu'à la fin de période → ligne plan sautée ; module facturé.
        BillingWatermark::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'prestation_key' => 'plan', 'currency' => 'EUR',
            'billed_through' => '2026-12-01 00:00:00',
        ]);
        $r = $this->run->emit($sub);
        $this->assertTrue($r->wasEmitted());
        $this->assertSame(1, $r->linesCount, 'plan sauté (déjà couvert), seul le module facturé');
        $this->assertSame(2900, $r->subtotalHtCents);
    }

    public function test_multi_tenant_isolation(): void
    {
        $a = $this->company();
        $suba = $this->sub($a);
        $b = $this->company();
        $this->sub($b);
        $this->run->emit($suba);
        $this->assertSame(1, Invoice::where('company_id', $a->id)->count());
        $this->assertSame(0, Invoice::where('company_id', $b->id)->count());
    }
}
