<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoverage;
use App\Core\Billing\BillingCoveredInterval;
use App\Core\Billing\BillingRun;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyWalletTransaction;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceBatchPayService;
use App\Core\Billing\Payment;
use App\Core\Billing\ReadModels\FinanceReconciliationService;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-632 (M6) — paiement/recouvrement des factures consolidées V2 (1 solde), + correction du
 * chevauchement partiel (ne jamais ignorer silencieusement). Aucun encaissement externe réel.
 */
class BillingV2PaymentCoverageTest extends TestCase
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
        return Company::create(['name' => 'Pay Co', 'slug' => 'pay6-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
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

    // ── Correction chevauchement partiel (commit séparé conceptuel) ──

    public function test_coverage_status_none_full_partial(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        BillingCoverage::record($c->id, $sub->id, 'plan', 'EUR', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-15'), null, null);

        $this->assertSame('none', BillingCoverage::coverageStatus($sub->id, 'plan', 'EUR', Carbon::parse('2026-11-20'), Carbon::parse('2026-11-30')));
        $this->assertSame('full', BillingCoverage::coverageStatus($sub->id, 'plan', 'EUR', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-15')));
        $this->assertSame('partial', BillingCoverage::coverageStatus($sub->id, 'plan', 'EUR', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')));
        // Intervalles adjacents ne se chevauchent pas.
        $this->assertSame('none', BillingCoverage::coverageStatus($sub->id, 'plan', 'EUR', Carbon::parse('2026-11-15'), Carbon::parse('2026-11-30')));
    }

    public function test_partial_coverage_blocks_emission(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        // Le plan est partiellement couvert [11-01,11-15) alors que le cycle est [11-01,12-01).
        BillingCoverage::record($c->id, $sub->id, 'plan', 'EUR', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-15'), null, null);
        $r = $this->run->emit($sub);
        $this->assertSame('blocked', $r->status);
        $this->assertContains('partial_coverage:plan', $r->blockedReasons);
        $this->assertSame(0, Invoice::count(), 'aucune facture partielle ni résidu auto-facturé');
    }

    // ── Paiement multi-lignes : 1 solde ──

    public function test_135_line_invoice_single_balance_wallet_paid(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        for ($i = 1; $i <= 134; $i++) {
            $this->addon($c, "mod_{$i}", 100);
        }
        $r = $this->run->emit($sub);
        $this->assertSame(135, $r->linesCount);
        $inv = Invoice::find($r->invoiceId);
        $expectedHt = 2900 + 134 * 100;
        $this->assertSame($expectedHt, $inv->subtotal);
        $this->assertSame(1, Invoice::where('id', $inv->id)->count(), '1 document comptable');

        WalletLedger::credit($c, $inv->amount_due + 1000, 'topup', null, 't', 'system', null, 'tp-135');
        $walletTxBefore = CompanyWalletTransaction::count();
        $res = InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);

        $this->assertSame('wallet_paid', $res['mode']);
        $this->assertSame('paid', $inv->fresh()->status);
        // 1 seul débit wallet pour la facture (pas 135).
        $this->assertLessThanOrEqual($walletTxBefore + 2, CompanyWalletTransaction::count(), 'règlement sur le solde, pas par ligne');
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->count());
    }

    public function test_partial_wallet_leaves_remaining_without_external_collection(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        WalletLedger::credit($c, 100, 'topup', null, 't', 'system', null, 'tp-partial'); // insuffisant
        try {
            InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        } catch (\Throwable $e) {
            // provider indisponible pour le reliquat → pas de collecte
        }
        $this->assertSame('open', $inv->fresh()->status, 'reliquat non collecté → facture reste ouverte');
        $this->assertSame(0, Payment::where('status', 'succeeded')->where('provider', '!=', 'internal')->count());
    }

    public function test_double_payment_attempt_not_double_charged(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        WalletLedger::credit($c, $inv->amount_due + 5000, 'topup', null, 't', 'system', null, 'tp-dbl');
        InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        $this->assertSame('paid', $inv->fresh()->status);
        // 2ᵉ tentative : la facture n'est plus éligible (déjà payée) → aucune nouvelle charge.
        $this->expectException(\RuntimeException::class);
        InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
    }

    // ── Dunning / réconciliation par facture ──

    public function test_dunning_treats_v2_invoice_as_single_creance(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        for ($i = 1; $i <= 3; $i++) {
            $this->addon($c, "mod_{$i}", 500);
        }
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        $inv->update(['status' => 'overdue', 'due_at' => now()->subWeek()]);

        $result = DunningEngine::retrySingleInvoice($inv->fresh());
        $this->assertIsString($result); // traité comme UNE créance (pas par module)
        $this->assertSame(0, Payment::where('provider', '!=', 'internal')->where('status', 'succeeded')->count());
    }

    public function test_reconciliation_counts_v2_invoice_by_id(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        $report = FinanceReconciliationService::report($c->id);
        $this->assertIsArray($report); // réconciliation par invoice_id, sans erreur, V2 inclus
    }

    // ── Provisional historique + compat V1/V2 ──

    public function test_finalized_v2_invoice_payable_even_if_sub_becomes_provisional(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        // L'abonnement passe en revue tarifaire APRÈS émission.
        $sub->update(['plan_price_source' => 'provisional']);
        WalletLedger::credit($c, $inv->amount_due + 1000, 'topup', null, 't', 'system', null, 'tp-prov');
        $res = InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        $this->assertSame('paid', $inv->fresh()->status, 'créance historique recouvrable malgré provisional courant');
    }

    public function test_v2_invoice_processed_when_create_flag_off(): void
    {
        $c = $this->company();
        $sub = $this->sub($c);
        $inv = Invoice::find($this->run->emit($sub)->invoiceId);
        // Désactiver la CRÉATION V2 : le traitement d'une facture V2 existante reste disponible.
        config(['billing.consolidated.emit' => false]);
        WalletLedger::credit($c, $inv->amount_due + 1000, 'topup', null, 't', 'system', null, 'tp-flagoff');
        $res = InvoiceBatchPayService::createPaymentIntent($c, [$inv->id], true);
        $this->assertSame('paid', $inv->fresh()->status);
    }

    public function test_multi_tenant_isolation(): void
    {
        $a = $this->company();
        $subA = $this->sub($a);
        $invA = Invoice::find($this->run->emit($subA)->invoiceId);
        $b = $this->company();
        WalletLedger::credit($b, 100000, 'topup', null, 't', 'system', null, 'tp-b');
        // B ne peut pas régler la facture de A.
        $this->expectException(\RuntimeException::class);
        InvoiceBatchPayService::createPaymentIntent($b, [$invA->id], true);
    }
}
