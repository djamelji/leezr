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

    public function test_premium_poppins_font_loads_locally_without_network(): void
    {
        // ADR-627: the premium platform font (Poppins) is embedded from the REPO-BUNDLED TTFs,
        // never fetched from Google at render time.
        $m = new \ReflectionMethod(\App\Modules\Core\Billing\Http\CompanyBillingController::class, 'localFontFaceCss');
        $m->setAccessible(true);
        $css = $m->invoke(null, 'Poppins');

        $this->assertNotNull($css, 'Poppins must resolve to a local @font-face');
        $this->assertStringContainsString('resources/fonts/poppins', $css, 'font loaded from the bundled repo fonts');
        $this->assertStringNotContainsString('http', $css, 'no remote URL in the font-face CSS');
        $this->assertStringContainsString("font-weight:700", $css, 'includes a bold weight for headings/totals');
    }
}
