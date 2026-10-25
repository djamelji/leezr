<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-626 (BILL-PERF-PDF). Invoice PDF rendering must be fast, self-contained (no remote font
 * fetch) and light (font subsetting). Guards against a regression to the ~882KB / network-dependent
 * render. Also checks French accents + the € sign survive the bundled font.
 */
class BillingPdfPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
    }

    public function test_invoice_pdf_is_light_valid_and_offline(): void
    {
        $c = Company::create(['name' => 'Pdf Co', 'slug' => 'pdf-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
        $inv = Invoice::create([
            'company_id' => $c->id, 'number' => 'INV-PDF-1', 'subtotal' => 2900, 'tax_amount' => 580,
            'tax_rate_bps' => 2000, 'amount' => 3480, 'amount_due' => 3480, 'wallet_credit_applied' => 0,
            'currency' => 'EUR', 'status' => 'open', 'issued_at' => now(), 'finalized_at' => now(), 'due_at' => now()->addMonth(),
            'billing_snapshot' => ['market_locale' => 'fr-FR'],
        ]);
        InvoiceLine::create(['invoice_id' => $inv->id, 'type' => 'addon', 'module_key' => 'wf', 'description' => 'Congés & Absences — accents é à ç €', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900]);

        $pdf = Pdf::loadView('billing.invoice-pdf', [
            'invoice' => $inv->load('lines', 'company', 'annexes', 'creditNotes'),
            'company' => $c, 'snap' => [], 'locale' => 'fr-FR', 'payments' => collect(),
            'walletSources' => [], 'typography' => ['active_family_name' => null, 'active_source' => null],
            'fontFaceCss' => null, 'primaryColor' => '#7367F0', 'platformConfig' => config('billing.platform'),
        ])->setOption('isFontSubsettingEnabled', true)->output();

        $this->assertSame('%PDF-', substr($pdf, 0, 5), 'must be a valid PDF');
        $this->assertLessThan(200_000, strlen($pdf), 'subset font keeps the PDF light (<200KB), not the ~882KB full-face');
    }
}
