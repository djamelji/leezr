<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillingCoveredInterval;
use App\Core\Billing\BillingRun;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceBatchPayService;
use App\Core\Billing\Payment;
use App\Core\Billing\ScheduledDebit;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-632 (M5) — facturation immédiate hors cycle + anti-double par intervalle + paiement V2.
 * Émission DÉSACTIVÉE par défaut ; testée en transaction isolée. Aucun encaissement externe réel.
 */
class BillingImmediateEmitTest extends TestCase
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
        config(['billing.consolidated.emit' => true]);
        Notification::fake();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Imm Co', 'slug' => 'imm-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
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

    private function immediateEvent(Company $c, Subscription $sub, string $module = 'workforce_planning', int $ht = 1496): BillableEvent
    {
        return BillableEvent::create([
            'company_id' => $c->id, 'subscription_id' => $sub->id, 'currency' => 'EUR', 'type' => 'module_activation',
            'module_key' => $module, 'service_period_start' => '2026-10-16 00:00:00', 'service_period_end' => '2026-11-01 00:00:00',
            'amount_ht_cents' => $ht, 'status' => 'eligible', 'bill_immediately' => true, 'idempotency_key' => 'imm-'.$module,
        ]);
    }

    public function test_flag_off_disables_immediate(): void
    {
        config(['billing.consolidated.emit' => false]);
        $c = $this->company();
        $sub = $this->sub($c);
        $r = $this->run->emitImmediate($this->immediateEvent($c, $sub));
        $this->assertSame('disabled', $r->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_immediate_emits_out_of_cycle_single_line_invoice(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $ev = $this->immediateEvent($c, $sub);
        $r = $this->run->emitImmediate($ev);

        $this->assertTrue($r->wasEmitted());
        $inv = Invoice::find($r->invoiceId);
        $this->assertSame(2, (int) $inv->billing_model_version);
        $this->assertSame(1, $r->linesCount, 'document hors cycle = 1 ligne');
        $this->assertSame(1496, $r->subtotalHtCents);
        $this->assertSame('invoiced', $ev->fresh()->status);
        $this->assertSame(1, BillingCoveredInterval::where('subscription_id', $sub->id)->where('prestation_key', 'workforce_planning')->count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, ScheduledDebit::count());
        Notification::assertNothingSent();
    }

    public function test_immediate_then_recurring_does_not_double_bill(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $this->addon($c, 'workforce_planning', 2900);
        // 1) Facturation immédiate du module pour la période du cycle courant.
        $ev = $this->immediateEvent($c, $sub, 'workforce_planning', 2900);
        $ev->update(['service_period_start' => '2026-11-01 00:00:00', 'service_period_end' => '2026-11-30 23:59:59']);
        // Ajuster la période de l'event pour qu'elle corresponde au cycle (couverture).
        $ev->update(['service_period_end' => '2026-12-01 00:00:00']);
        $this->run->emitImmediate($ev);

        // 2) Émission récurrente du cycle → le module NE doit PAS être refacturé (intervalle couvert).
        $r = $this->run->emit($sub);
        $this->assertTrue($r->wasEmitted());
        $moduleLines = Invoice::find($r->invoiceId)->lines()->where('module_key', 'workforce_planning')->count();
        $this->assertSame(0, $moduleLines, 'module déjà facturé en immédiat → pas de double au cycle');
    }

    public function test_immediate_replay_is_idempotent(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $ev = $this->immediateEvent($c, $sub);
        $first = $this->run->emitImmediate($ev);
        $second = $this->run->emitImmediate($ev->fresh());
        $this->assertTrue($first->wasEmitted());
        $this->assertSame('replayed', $second->status);
        $this->assertSame($first->invoiceId, $second->invoiceId);
        $this->assertSame(1, Invoice::count());
    }

    public function test_provisional_blocks_immediate(): void
    {
        $c = $this->company();
        $sub = $this->sub($c, ['plan_price_source' => 'provisional']);
        $r = $this->run->emitImmediate($this->immediateEvent($c, $sub));
        $this->assertSame('blocked', $r->status);
        $this->assertContains('cycle_hold', $r->blockedReasons);
        $this->assertSame(0, Invoice::count());
    }

    public function test_payment_v2_wallet_covers(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $ev = $this->immediateEvent($c, $sub);
        $r = $this->run->emitImmediate($ev);
        $inv = Invoice::find($r->invoiceId);

        // Créditer le wallet au-delà du dû, puis régler via le service V1 (agnostique au modèle).
        WalletLedger::credit($c, 5000, 'test_topup', null, 'topup', 'system', null, 'topup-1');
        $intent = InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);

        $this->assertNotEmpty($intent['paid_invoice_ids'] ?? [], 'wallet suffisant → facture V2 réglée');
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->count(), 'aucun encaissement externe');
    }

    public function test_payment_v2_no_coverage_no_external_collection_invoice_stays_open(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $ev = $this->immediateEvent($c, $sub);
        $inv = Invoice::find($this->run->emitImmediate($ev)->invoiceId);

        // Wallet insuffisant + aucun provider externe collectable (null/internal/Stripe non configuré) :
        // la tentative de paiement provider ne peut PAS aboutir → la facture reste OPEN, statut exact,
        // AUCUN encaissement externe. (Le batch-pay provider passe par Stripe, indisponible ici.)
        try {
            InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        } catch (\Throwable $e) {
            // provider indisponible → aucune collecte ; comportement attendu
        }
        $this->assertSame('open', $inv->fresh()->status, 'provider indisponible → facture reste ouverte');
        $this->assertSame(0, Payment::where('status', 'succeeded')->where('provider', '!=', 'internal')->count(), 'aucun encaissement externe');
    }

    private function addon(Company $c, string $key, int $cents): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $c->id, 'module_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR',
            'interval' => 'monthly', 'activated_at' => now()->subDay(), 'deactivated_at' => null,
        ]);
    }
}
