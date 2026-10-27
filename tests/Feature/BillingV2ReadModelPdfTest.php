<?php

namespace Tests\Feature;

use App\Core\Billing\BillingRun;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Plans\Plan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-632 (M8) — read models + PDF des factures consolidées V2. Montants identiques (read model =
 * ventilation analytique = facture = PDF), aucun recalcul, V1 préservé, RBAC/multi-tenant.
 */
class BillingV2ReadModelPdfTest extends TestCase
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
        return Company::create(['name' => 'Rd Co', 'slug' => 'rd-'.uniqid(), 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function sub(Company $c): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => 1, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => '2026-11-01 00:00:00', 'current_period_end' => '2026-11-30 23:59:59',
            'v2_effective_at' => '2026-10-01 00:00:00', 'commercial_policy_version' => 'v2.0',
        ]);
    }

    private function addon(Company $c, string $key, int $cents): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $c->id, 'module_key' => $key, 'amount_cents' => $cents, 'currency' => 'EUR',
            'interval' => 'monthly', 'activated_at' => now()->subDay(), 'deactivated_at' => null,
        ]);
    }

    private function emitV2(Company $c, int $modules = 1): Invoice
    {
        $sub = $this->sub($c);
        for ($i = 1; $i <= $modules; $i++) {
            $this->addon($c, "mod_{$i}", 100);
        }

        return Invoice::find($this->run->emit($sub)->invoiceId);
    }

    public function test_v2_detail_has_consolidated_sections_reconciled(): void
    {
        $c = $this->company();
        $inv = $this->emitV2($c, 2);
        $detail = CompanyBillingReadService::invoiceDetail($c, $inv->id);

        $this->assertSame(2, $detail['billing_model_version']);
        $cs = $detail['consolidated_sections'];
        $this->assertArrayHasKey('sections', $cs);
        // Réconciliation : synthèse fiscale = totaux persistés de la facture.
        $this->assertSame((int) $inv->subtotal, $cs['fiscal_summary']['subtotal_ht_cents']);
        $this->assertSame((int) $inv->tax_amount, $cs['fiscal_summary']['tax_cents']);
        $this->assertSame((int) $inv->amount, $cs['fiscal_summary']['total_ttc_cents']);
        // Σ des sections = totaux facture (aucun recalcul).
        $sumHt = collect($cs['sections'])->sum('subtotal_ht_cents');
        $sumTax = collect($cs['sections'])->sum('subtotal_tax_cents');
        $this->assertSame((int) $inv->subtotal, $sumHt);
        $this->assertSame((int) $inv->tax_amount, $sumTax);
        // Chaque ligne porte sa période de service.
        $this->assertNotNull($detail['lines'][0]['service_period_start']);
    }

    public function test_v1_detail_has_no_consolidated_sections(): void
    {
        $c = $this->company();
        $inv = Invoice::create([
            'company_id' => $c->id, 'billing_model_version' => 1, 'number' => 'INV-V1-R', 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
        ]);
        $detail = CompanyBillingReadService::invoiceDetail($c, $inv->id);
        $this->assertSame(1, $detail['billing_model_version']);
        $this->assertSame([], $detail['consolidated_sections'], 'V1 : pas de sections consolidées');
    }

    public function test_135_line_detail_reconciles(): void
    {
        $c = $this->company();
        $inv = $this->emitV2($c, 134);
        $detail = CompanyBillingReadService::invoiceDetail($c, $inv->id);
        $this->assertCount(135, $detail['lines']);
        $sumHt = collect($detail['consolidated_sections']['sections'])->sum('subtotal_ht_cents');
        $this->assertSame((int) $inv->subtotal, $sumHt);
    }

    public function test_tenant_isolation_on_detail(): void
    {
        $a = $this->company();
        $inv = $this->emitV2($a, 1);
        $other = $this->company();
        $this->assertNull(CompanyBillingReadService::invoiceDetail($other, $inv->id), 'facture d\'un autre tenant → null');
    }

    public function test_v2_invoice_readable_with_emit_off(): void
    {
        $c = $this->company();
        $inv = $this->emitV2($c, 1);
        config(['billing.consolidated.emit' => false]); // désactiver la création
        $detail = CompanyBillingReadService::invoiceDetail($c, $inv->id);
        $this->assertSame(2, $detail['billing_model_version'], 'facture V2 reste consultable, émission OFF');
        $this->assertNotEmpty($detail['consolidated_sections']);
    }

    public function test_v2_pdf_renders_multipage_offline(): void
    {
        $c = $this->company();
        $inv = $this->emitV2($c, 134)->load('lines', 'company', 'annexes', 'creditNotes');

        $pdf = Pdf::loadView('billing.invoice-pdf', [
            'invoice' => $inv, 'company' => $c, 'snap' => [], 'locale' => 'fr-FR', 'payments' => collect(),
            'walletSources' => [], 'typography' => ['active_family_name' => null, 'active_source' => null],
            'fontFaceCss' => null, 'primaryColor' => '#7367F0', 'platformConfig' => config('billing.platform'),
        ])->setOption('isFontSubsettingEnabled', true)->output();

        $this->assertSame('%PDF-', substr($pdf, 0, 5), 'PDF V2 valide');
        $this->assertGreaterThan(5000, strlen($pdf), '135 lignes → document substantiel (multipage)');
    }
}
