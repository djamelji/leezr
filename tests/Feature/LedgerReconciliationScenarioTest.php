<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test accounting reconciliation (remediation final deliverable).
 *
 * Exercises a full billing lifecycle across payment methods and proves the
 * double-entry ledger reconciles: HT/VAT/TTC separated (F3), non-Stripe payment
 * clears AR (F1), postings idempotent (F4), and SUM(debit) == SUM(credit).
 */
class LedgerReconciliationScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_scenario_reconciles(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $company = Company::create(['name' => 'Recon Co', 'slug' => 'recon-co', 'jobdomain_key' => 'logistique']);

        $mk = fn (int $sub, int $tax, int $due) => Invoice::create([
            'company_id' => $company->id, 'currency' => 'EUR', 'status' => 'open',
            'subtotal' => $sub, 'tax_amount' => $tax, 'tax_rate_bps' => 2000,
            'amount' => $sub + $tax, 'wallet_credit_applied' => 0, 'amount_due' => $due,
        ]);

        // Invoice 1: 1000 HT + 200 VAT = 1200 TTC → paid offline (non-Stripe).
        $inv1 = $mk(1000, 200, 1200);
        LedgerService::recordInvoiceIssued($inv1);
        Payment::create([
            'company_id' => $company->id, 'invoice_id' => $inv1->id, 'amount' => 1200,
            'currency' => 'EUR', 'status' => 'succeeded', 'provider' => 'offline',
            'provider_payment_id' => 'off-recon-1',
        ]);

        // Invoice 2: 500 HT + 100 VAT = 600 TTC → written off (bad debt).
        $inv2 = $mk(500, 100, 600);
        LedgerService::recordInvoiceIssued($inv2);
        LedgerService::recordWriteOff($inv2);

        // Idempotency: replay must not change the books.
        LedgerService::recordInvoiceIssued($inv1);
        LedgerService::recordWriteOff($inv2);

        $b = LedgerService::trialBalance($company->id);

        $this->assertSame(0.0, $b['AR'], 'AR fully cleared (1200 paid, 600 written off).');
        $this->assertSame(1200.0, $b['CASH'], 'Only the offline payment is cash.');
        $this->assertSame(-1500.0, $b['REVENUE'], 'Revenue is HT only (1000 + 500).');
        $this->assertSame(-300.0, $b['VAT_PAYABLE'], 'VAT isolated (200 + 100).');
        $this->assertSame(600.0, $b['BAD_DEBT'], 'Written-off TTC.');

        // Global double-entry reconciliation.
        $this->assertSame(
            (float) LedgerEntry::where('company_id', $company->id)->sum('debit'),
            (float) LedgerEntry::where('company_id', $company->id)->sum('credit'),
            'Global ledger must reconcile: SUM(debit) == SUM(credit).'
        );

        // Per-correlation balance invariant.
        $imbalanced = LedgerEntry::where('company_id', $company->id)
            ->selectRaw('correlation_id, SUM(debit) - SUM(credit) as delta')
            ->groupBy('correlation_id')
            ->havingRaw('SUM(debit) - SUM(credit) <> 0')
            ->get();
        $this->assertCount(0, $imbalanced, 'Every correlation must balance.');
    }
}
