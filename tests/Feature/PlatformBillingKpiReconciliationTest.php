<?php

namespace Tests\Feature;

use App\Core\Billing\LedgerEntry;
use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Billing Cockpit 2.0 — Phase A/B reconciliation (ADR-582).
 * Locks the two platform KPIs to the financial ledger, in INTEGER CENTS, reproducing the exact
 * production composition that produced "5 700 €" / "6 840 €" (which were cents shown as euros ×100):
 *   - Revenue trend (30d) = Σ credit on invoice_issued/REVENUE within the window  → 5700 cents = 57,00 €
 *   - AR outstanding (all-time) = Σ debit − Σ credit on account AR (write-offs reduce it) → 6840 cents = 68,40 €
 */
class PlatformBillingKpiReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(array $attrs): void
    {
        LedgerEntry::create(array_merge([
            'company_id' => 1,
            'currency' => 'EUR',
            'debit' => 0,
            'credit' => 0,
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => 'test-'.($attrs['account_code'] ?? 'x').'-'.($attrs['reference_id'] ?? 0).'-'.($attrs['entry_type'] ?? 'e'),
            'recorded_at' => now(),
        ], $attrs));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Invoice #1 (company 2) — issued 5 months ago, then WRITTEN OFF (uncollectible).
        $may = now()->copy()->subMonths(5);
        $this->ledger(['company_id' => 2, 'reference_id' => 1, 'entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 3480, 'recorded_at' => $may]);
        $this->ledger(['company_id' => 2, 'reference_id' => 1, 'entry_type' => 'invoice_issued', 'account_code' => 'REVENUE', 'credit' => 3480, 'recorded_at' => $may]);
        $this->ledger(['company_id' => 2, 'reference_id' => 1, 'entry_type' => 'writeoff', 'account_code' => 'BAD_DEBT', 'debit' => 3480, 'recorded_at' => $may->copy()->addMonth()]);
        $this->ledger(['company_id' => 2, 'reference_id' => 1, 'entry_type' => 'writeoff', 'account_code' => 'AR', 'credit' => 3480, 'recorded_at' => $may->copy()->addMonth()]);

        // Invoices #2/#3/#4 (company 1) — issued now, OPEN (unpaid): HT + VAT split.
        foreach ([[2, 1900, 380, 2280], [3, 2900, 580, 3480], [4, 900, 180, 1080]] as [$ref, $ht, $vat, $ttc]) {
            $this->ledger(['reference_id' => $ref, 'entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => $ttc]);
            $this->ledger(['reference_id' => $ref, 'entry_type' => 'invoice_issued', 'account_code' => 'REVENUE', 'credit' => $ht]);
            $this->ledger(['reference_id' => $ref, 'entry_type' => 'invoice_issued', 'account_code' => 'VAT_PAYABLE', 'credit' => $vat]);
        }
    }

    public function test_ar_outstanding_is_integer_cents_and_excludes_written_off(): void
    {
        $out = PlatformBillingWidgetsReadService::arOutstandingGlobal()['outstanding'];

        $this->assertIsInt($out, 'outstanding is integer cents');
        $this->assertSame(6840, $out, 'AR net = 10320 debit − 3480 write-off = 6840 cents (= 68,40 €)');
        // Reconciles exactly to the sum of the three OPEN invoices TTC.
        $this->assertSame(2280 + 3480 + 1080, $out);
    }

    public function test_revenue_trend_30d_is_integer_cents_HT_within_window(): void
    {
        $spec = PlatformBillingWidgetsReadService::revenueTrendGlobal(now()->copy()->subDays(30), now());
        $total = array_sum($spec['series']);

        $this->assertContainsOnly('integer', $spec['series'], true, 'series are integer cents');
        $this->assertSame(1900 + 2900 + 900, $total, 'revenue 30d = HT of the 3 recent invoices = 5700 cents (= 57,00 €)');
        // The 5-month-old written-off invoice REVENUE (3480) is OUTSIDE the 30-day window.
        $this->assertSame(5700, $total);
    }
}
