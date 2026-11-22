<?php

namespace Tests\Feature;

use App\Core\Billing\LedgerEntry;
use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-862 (Leezr 5.0 L7 closure) — « Facturé ce mois (HT) » on the V35 cockpit. The global KPIs stay cached
 * (ADR-312) but an invoice issued after the cache was filled is counted at once ; and the tile is « invoiced »,
 * never « collected » (the sum is ledger invoice_issued REVENUE credits, not payments).
 */
class Adr862InvoicedThisMonthTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, float $amount): void
    {
        LedgerEntry::create(['company_id' => $company->id, 'entry_type' => 'invoice_issued', 'account_code' => 'REVENUE', 'debit' => 0,
            'credit' => $amount, 'currency' => 'EUR', 'reference_type' => 'invoice', 'reference_id' => random_int(1, 99999),
            'correlation_id' => (string) Str::uuid(), 'recorded_at' => now()]);
    }

    public function test_an_invoice_issued_after_the_cache_was_filled_is_counted_at_once(): void
    {
        Cache::flush();
        $company = Company::create(['name' => 'Ledger Co', 'slug' => 'ledger-co']);
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $this->invoice($company, 100.00);
        $this->assertEqualsWithDelta(100.0, PlatformBillingWidgetsReadService::kpisDataset('global', null, $from, $to)['revenue'], 0.001);

        $this->invoice($company, 42.50); // e.g. a plan change invoiced a minute later
        $this->assertEqualsWithDelta(142.5, PlatformBillingWidgetsReadService::kpisDataset('global', null, $from, $to)['revenue'], 0.001);
    }

    public function test_the_cockpit_tile_says_invoiced_never_collected(): void
    {
        $fr = json_decode(file_get_contents(base_path('resources/js/plugins/i18n/locales/fr.json')), true)['cockpit']['collected'];
        $en = json_decode(file_get_contents(base_path('resources/js/plugins/i18n/locales/en.json')), true)['cockpit']['collected'];
        $this->assertSame('Facturé ce mois (HT)', $fr['label']);
        $this->assertStringNotContainsStringIgnoringCase('encaiss', $fr['label']);
        $this->assertStringContainsString("pas l'encaissé", $fr['context']);
        $this->assertStringNotContainsStringIgnoringCase('collected', $en['label']);
    }
}
