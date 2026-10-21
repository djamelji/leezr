<?php

namespace Tests\Feature;

use App\Core\Billing\LedgerEntry;
use App\Core\Billing\ReadModels\CompanyBillingReadService;
use App\Core\Billing\Subscription;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Billing Cockpit 2.0 — Phase D (ADR-585). The company-scoped finance summary shares the Platform
 * ledger definitions (billed / paid / outstanding = ledger AR / overdue), is isolated per company,
 * and the deferred-renewal state (ADR-575) is surfaced to the company.
 */
class CompanyFinanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->a = Company::create(['name' => 'A', 'slug' => 'co-a', 'market_key' => 'FR']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'co-b', 'market_key' => 'FR']);
    }

    private function invoice(int $company, array $a): int
    {
        return DB::table('invoices')->insertGetId(array_merge([
            'company_id' => $company, 'number' => 'INV-'.uniqid(), 'amount' => 0, 'subtotal' => 0, 'tax_amount' => 0,
            'amount_due' => 0, 'currency' => 'EUR', 'status' => 'open', 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    private function ledger(int $company, array $a): void
    {
        LedgerEntry::create(array_merge([
            'company_id' => $company, 'currency' => 'EUR', 'debit' => 0, 'credit' => 0,
            'reference_type' => 'invoice', 'reference_id' => 1, 'correlation_id' => 'cfs-'.uniqid(), 'recorded_at' => now(),
        ], $a));
    }

    public function test_company_finance_summary_shares_ledger_definitions_and_handles_partial_payment(): void
    {
        // Invoice 1: open, due in the future, 2280 fully due.
        $i1 = $this->invoice($this->a->id, ['amount' => 2280, 'amount_due' => 2280, 'status' => 'open', 'due_at' => now()->addDays(10)]);
        // Invoice 2: 3480 billed, partially paid → 1000 remaining, due date PAST (overdue).
        $i2 = $this->invoice($this->a->id, ['amount' => 3480, 'amount_due' => 1000, 'status' => 'open', 'due_at' => now()->subDays(5)]);
        // Ledger: AR debits for both; a partial payment of 2480 on invoice 2 (CASH debit, AR credit).
        $this->ledger($this->a->id, ['reference_id' => $i1, 'entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 2280]);
        $this->ledger($this->a->id, ['reference_id' => $i2, 'entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 3480]);
        $this->ledger($this->a->id, ['reference_type' => 'payment', 'reference_id' => 1, 'entry_type' => 'payment_received', 'account_code' => 'CASH', 'debit' => 2480]);
        $this->ledger($this->a->id, ['reference_type' => 'payment', 'reference_id' => 1, 'entry_type' => 'payment_received', 'account_code' => 'AR', 'credit' => 2480]);

        // Company B noise — must not leak into A's summary.
        $this->invoice($this->b->id, ['amount' => 99999, 'amount_due' => 99999, 'status' => 'open', 'due_at' => now()->subDay()]);
        $this->ledger($this->b->id, ['entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 99999]);

        $eur = collect(CompanyBillingReadService::financeSummary($this->a))->firstWhere('currency', 'EUR');

        $this->assertSame(5760, $eur['billed_cents'], 'billed = 2280 + 3480');
        $this->assertSame(2480, $eur['paid_cents'], 'paid = ledger CASH net');
        $this->assertSame(3280, $eur['outstanding_cents'], 'outstanding = ledger AR net (5760 − 2480) = Σ amount_due');
        $this->assertSame(1000, $eur['overdue_cents'], 'overdue = past-due invoice 2 remaining');
        $this->assertTrue($eur['reconciled'], 'ledger AR reconciles to invoice balances');
    }

    public function test_isolation_company_a_summary_excludes_company_b(): void
    {
        $this->invoice($this->b->id, ['amount' => 99999, 'amount_due' => 99999, 'status' => 'open']);
        $this->ledger($this->b->id, ['entry_type' => 'invoice_issued', 'account_code' => 'AR', 'debit' => 99999]);

        $summaryA = CompanyBillingReadService::financeSummary($this->a);
        // A has no financial activity → no EUR row leaks B's 99999.
        $eur = collect($summaryA)->firstWhere('currency', 'EUR');
        $this->assertTrue($eur === null || $eur['outstanding_cents'] === 0, "Company A must not see Company B's receivable");
    }

    public function test_deferred_renewal_is_surfaced_to_the_company(): void
    {
        Subscription::create([
            'company_id' => $this->a->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'is_current' => true, 'current_period_start' => now()->subMonths(5), 'current_period_end' => now()->subMonths(4),
            'metadata' => ['renewal_review_required' => true, 'renewal_deferred_at' => now()->toIso8601String()],
        ]);

        $sub = CompanyBillingReadService::currentSubscription($this->a);

        $this->assertTrue($sub['renewal_review_required'], 'company sees its renewal is under review (ADR-575)');
        $this->assertNotNull($sub['renewal_deferred_at']);
    }
}
