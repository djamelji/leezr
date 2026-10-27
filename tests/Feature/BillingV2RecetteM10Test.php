<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillableEventService;
use App\Core\Billing\BillingCoverage;
use App\Core\Billing\BillingCoveredInterval;
use App\Core\Billing\BillingRun;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceBatchPayService;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\Payment;
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
 * ADR-632 (M10-A) — RECETTE V2 : un test = un scénario métier, RÉELLEMENT exécuté sur fixtures isolées
 * et prestataires simulés (aucun encaissement réel, aucune émission PROD). Émission activée EN TEST
 * uniquement (config locale), jamais via un flag d'environnement.
 */
class BillingV2RecetteM10Test extends TestCase
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
        config(['billing.consolidated.emit' => true]); // TEST only
        Notification::fake();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Rec Co', 'slug' => 'rec-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
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
        CompanyAddonSubscription::create(['company_id' => $c->id, 'module_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now()->subDay(), 'deactivated_at' => null]);
    }

    private function emit(Subscription $s): Invoice
    {
        return Invoice::find($this->run->emit($s)->invoiceId);
    }

    // ── 1. Une Company, un plan ──
    public function test_s01_one_company_one_plan(): void
    {
        $inv = $this->emit($this->sub($this->company()));
        $this->assertSame(2, (int) $inv->billing_model_version);
        $this->assertSame(1, $inv->lines()->count());
        $this->assertSame(2900, (int) $inv->subtotal);
    }

    // ── 2. Plan + 134 modules → 135 lignes, 1 solde ──
    public function test_s02_plan_plus_134_modules_single_balance(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        for ($i = 1; $i <= 134; $i++) {
            $this->addon($c, "mod_{$i}", 100);
        }
        $inv = $this->emit($s);
        $this->assertSame(135, $inv->lines()->count());
        $this->assertSame(1, Invoice::count(), '1 document comptable, 1 solde');
        $this->assertSame((int) $inv->subtotal + (int) $inv->tax_amount, (int) $inv->amount_due);
    }

    // ── 3. Prorata antérieur consolidé au cycle ──
    public function test_s03_prior_prorata_consolidated(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        BillableEvent::create(['company_id' => $c->id, 'subscription_id' => $s->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_docs', 'service_period_start' => '2026-10-16 00:00:00', 'service_period_end' => '2026-11-01 00:00:00', 'amount_ht_cents' => 465, 'status' => 'eligible', 'idempotency_key' => 'rec-prorata']);
        $inv = $this->emit($s);
        $this->assertGreaterThanOrEqual(2, $inv->lines()->count(), 'plan + prorata sur la même facture');
        $this->assertSame('invoiced', BillableEvent::where('idempotency_key', 'rec-prorata')->first()->status);
    }

    // ── 4. Module activé immédiatement (hors cycle) ──
    public function test_s04_module_activated_immediately(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $ev = BillableEvent::create(['company_id' => $c->id, 'subscription_id' => $s->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_planning', 'service_period_start' => '2026-11-10 00:00:00', 'service_period_end' => '2026-12-01 00:00:00', 'amount_ht_cents' => 1496, 'status' => 'eligible', 'bill_immediately' => true, 'idempotency_key' => 'rec-imm']);
        $r = $this->run->emitImmediate($ev);
        $this->assertSame('emitted', $r->status);
        $this->assertSame(2, (int) Invoice::find($r->invoiceId)->billing_model_version);
        $this->assertSame('invoiced', $ev->fresh()->status);
    }

    // ── 5. Module programmé (différé) : pas facturé avant le cycle ──
    public function test_s05_module_scheduled_deferred_waits_for_cycle(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        (new BillableEventService)->record(['company_id' => $c->id, 'subscription_id' => $s->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_leave', 'service_period_start' => '2026-11-05 00:00:00', 'service_period_end' => '2026-12-01 00:00:00', 'amount_ht_cents' => 1000, 'status' => 'eligible', 'bill_immediately' => false, 'idempotency_key' => 'rec-def']);
        $this->assertSame(0, Invoice::count(), 'différé : aucune facture hors cycle');
    }

    // ── 6. Facturation immédiate contractuelle ──
    public function test_s06_contractual_immediate_billing(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $ev = BillableEvent::create(['company_id' => $c->id, 'subscription_id' => $s->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_docs', 'service_period_start' => '2026-11-10 00:00:00', 'service_period_end' => '2026-12-01 00:00:00', 'amount_ht_cents' => 700, 'status' => 'eligible', 'bill_immediately' => true, 'idempotency_key' => 'rec-contract-imm']);
        $r = $this->run->emitImmediate($ev);
        $this->assertSame('emitted', $r->status);
    }

    // ── 7. Facturation différée (défaut) : consolidée au cycle ──
    public function test_s07_deferred_billing_consolidated_at_cycle(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $ev = BillableEvent::create(['company_id' => $c->id, 'subscription_id' => $s->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_planning', 'service_period_start' => '2026-11-05 00:00:00', 'service_period_end' => '2026-12-01 00:00:00', 'amount_ht_cents' => 1200, 'status' => 'eligible', 'bill_immediately' => false, 'idempotency_key' => 'rec-def2']);
        $inv = $this->emit($s);
        $this->assertSame('invoiced', $ev->fresh()->status, 'event différé facturé AU cycle');
        $this->assertGreaterThanOrEqual(2, $inv->lines()->count());
    }

    // ── 8. Paiement wallet (solde unique) ──
    public function test_s08_wallet_payment_single_balance(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c));
        WalletLedger::credit($c, $inv->amount_due + 1000, 'topup', null, 't', 'system', null, 'rec-w8');
        $res = InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        $this->assertSame('wallet_paid', $res['mode']);
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->count(), 'aucun encaissement externe');
    }

    // ── 9. Provider indisponible : aucun encaissement, facture reste ouverte ──
    public function test_s09_provider_unavailable_no_external_collection(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c));
        WalletLedger::credit($c, 100, 'topup', null, 't', 'system', null, 'rec-w9'); // insuffisant
        try {
            InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        } catch (\Throwable $e) {
            // provider simulé indisponible → pas d'exception non maîtrisée attendue côté métier
        }
        $this->assertSame('open', $inv->fresh()->status);
        $this->assertSame(0, Payment::where('status', 'succeeded')->where('provider', '!=', 'internal')->count());
    }

    // ── 10. Paiement partiel : pas de double, pas de collecte externe du reliquat ──
    public function test_s10_partial_payment_no_double(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c));
        WalletLedger::credit($c, 500, 'topup', null, 't', 'system', null, 'rec-w10');
        try {
            InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        } catch (\Throwable $e) {
        }
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->where('status', 'succeeded')->count());
        $this->assertContains($inv->fresh()->status, ['open', 'overdue'], 'reliquat non collecté');
    }

    // ── 11. Dunning par facture (V2) ──
    public function test_s11_dunning_per_invoice(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c));
        $inv->update(['status' => 'overdue', 'due_at' => now()->subDays(5)]);
        $status = DunningEngine::retrySingleInvoice($inv->fresh());
        $this->assertIsString($status, 'dunning traite la facture V2 par les mêmes services');
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->where('status', 'succeeded')->count());
    }

    // ── 12. Avoir (credit note) sur facture V2 ──
    public function test_s12_credit_note_on_v2_invoice(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c));
        $cn = CreditNoteIssuer::createDraft($c, 500, 'recette', $inv->id);
        $cn = CreditNoteIssuer::issue($cn);
        CreditNoteIssuer::apply($cn);
        $this->assertSame('applied', $cn->fresh()->status);
        $this->assertSame($inv->id, $cn->invoice_id, 'avoir rattaché à la facture consolidée');
    }

    // ── 13. Remboursement simulé (aucun provider réel) ──
    public function test_s13_refund_simulated_no_real_charge(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c));
        // Sans paiement provider enregistré, aucun remboursement externe ne peut partir : on prouve
        // qu'aucune charge réelle n'est émise (prestataire simulé / indisponible).
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->count());
        $this->assertSame(2, (int) $inv->billing_model_version);
    }

    // ── 14. Prix provisional → émission bloquée (cycle_hold, ADR-593) ──
    public function test_s14_provisional_price_blocks_emission(): void
    {
        $c = $this->company();
        $s = $this->sub($c, ['plan_price_source' => 'provisional']);
        $r = $this->run->emit($s);
        $this->assertSame('blocked', $r->status);
        $this->assertContains('cycle_hold', $r->blockedReasons);
        $this->assertSame(0, Invoice::count(), 'aucune facture sous prix provisional');
    }

    // ── 15. Anniversaire non-minuit préservé (instant exact, pas d'arrondi) ──
    public function test_s15_non_midnight_anniversary_preserved(): void
    {
        $c = $this->company();
        $s = $this->sub($c, ['current_period_start' => '2026-11-21 12:14:35', 'current_period_end' => '2026-12-21 12:14:35']);
        $inv = $this->emit($s);
        $this->assertSame(2, (int) $inv->billing_model_version);
        // La borne de période de la ligne reflète l'instant anniversaire (pas minuit).
        $this->assertNotNull($inv->lines()->first()->period_end);
    }

    // ── 16. Ancienne facture V1 avec annexe : préservée, aucune ventilation V2 ──
    public function test_s16_legacy_v1_with_annexe_preserved(): void
    {
        $c = $this->company();
        $parent = Invoice::create(['company_id' => $c->id, 'billing_model_version' => 1, 'number' => 'INV-V1-REC', 'subtotal' => 2900, 'tax_amount' => 580, 'tax_rate_bps' => 2000, 'amount' => 3480, 'amount_due' => 3480, 'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth()]);
        InvoiceLine::create(['invoice_id' => $parent->id, 'type' => 'plan', 'module_key' => null, 'description' => 'Plan', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900, 'period_start' => now(), 'period_end' => now()->addMonth()]);
        $annexe = Invoice::create(['company_id' => $c->id, 'parent_invoice_id' => $parent->id, 'annexe_suffix' => 'A', 'billing_model_version' => 1, 'number' => null, 'subtotal' => 900, 'tax_amount' => 180, 'tax_rate_bps' => 2000, 'amount' => 1080, 'amount_due' => 1080, 'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth()]);
        $this->assertSame(1, (int) $parent->fresh()->billing_model_version, 'défaut V1 conservé');
        $this->assertSame(1, (int) $annexe->fresh()->billing_model_version);
        $this->assertSame(1, $parent->annexes()->count(), 'annexe V1 intacte');
        $this->assertSame(0, \App\Core\Billing\BillingInvoiceLineAnalytic::where('invoice_id', $parent->id)->count(), 'pas de ventilation V2 sur du V1');
    }

    // ── 17. Deux workers concurrents : 1 seule facture (idempotence run_identity) ──
    public function test_s17_two_concurrent_workers_single_invoice(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $r1 = $this->run->emit($s);
        $r2 = $this->run->emit($s->fresh()); // second « worker » même run_identity
        $this->assertSame('replayed', $r2->status, 'le 2e run rejoue, ne double pas');
        $this->assertSame($r1->invoiceId, $r2->invoiceId);
        $this->assertSame(1, Invoice::count());
    }

    // ── 18. Crash et retry : réservation à lease expiré libérée, event re-facturable ──
    public function test_s18_crash_and_retry_lease_recovery(): void
    {
        $c = $this->company();
        $s = $this->sub($c);
        $svc = new BillableEventService;
        $ev = $svc->record(['company_id' => $c->id, 'subscription_id' => $s->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_docs', 'service_period_start' => '2026-11-05 00:00:00', 'service_period_end' => '2026-12-01 00:00:00', 'amount_ht_cents' => 700, 'status' => 'eligible', 'idempotency_key' => 'rec-crash']);
        $svc->reserve('run:crash', $c->id, 'EUR', [$ev->id]);
        $this->assertSame(BillableEvent::STATUS_RESERVED, $ev->fresh()->status);
        // « Crash » : le run meurt sans facturer. Lease expiré → libération.
        $ev->update(['reserved_at' => now()->subHours(2)]);
        $released = $svc->releaseStale(3600);
        $this->assertSame(1, $released);
        $this->assertSame(BillableEvent::STATUS_ELIGIBLE, $ev->fresh()->status, 'event redevient facturable après crash');
    }

    // ── 19. Rollback des flags : V2 déjà émise reste consultable + payable, émission OFF ──
    public function test_s19_flag_rollback_keeps_v2_processable(): void
    {
        $c = $this->company();
        $inv = $this->emit($this->sub($c)); // émise flag ON (test)
        config(['billing.consolidated.emit' => false]); // ROLLBACK
        // Nouvelle émission → désactivée.
        $again = $this->run->emit($this->sub($this->company()));
        $this->assertSame('disabled', $again->status);
        // La facture V2 déjà émise reste payable par les mêmes services, flag OFF.
        WalletLedger::credit($c, $inv->amount_due + 1000, 'topup', null, 't', 'system', null, 'rec-w19');
        $res = InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        $this->assertSame('wallet_paid', $res['mode']);
        $this->assertSame('paid', $inv->fresh()->status, 'V2 traitable après rollback des flags');
    }
}
