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
 * Double-entry ledger reliability (audit F1/F3/F4).
 *  - F4: every financial event posts exactly once (idempotent).
 *  - F3: REVENUE carries HT only; collected VAT is isolated on VAT_PAYABLE.
 *  - F1: every succeeded payment (not only Stripe) clears AR with a CASH posting.
 */
class LedgerReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->company = Company::create(['name' => 'Co', 'slug' => 'co', 'jobdomain_key' => 'logistique']);
    }

    private function invoice(array $attrs): Invoice
    {
        return Invoice::create(array_merge([
            'company_id' => $this->company->id,
            'currency' => 'EUR',
            'status' => 'open',
            'wallet_credit_applied' => 0,
            'tax_rate_bps' => 2000,
        ], $attrs));
    }

    private function balanced(): void
    {
        $this->assertSame(
            (float) LedgerEntry::where('company_id', $this->company->id)->sum('debit'),
            (float) LedgerEntry::where('company_id', $this->company->id)->sum('credit'),
            'Ledger must stay balanced: SUM(debit) == SUM(credit).'
        );
    }

    // ── F4: idempotency ──────────────────────────────────────

    public function test_invoice_issued_posts_exactly_once(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax_amount' => 200, 'amount' => 1200, 'amount_due' => 1200]);

        LedgerService::recordInvoiceIssued($invoice);
        LedgerService::recordInvoiceIssued($invoice); // replay

        $this->assertSame(1, LedgerEntry::where('entry_type', 'invoice_issued')
            ->where('reference_id', $invoice->id)->where('account_code', 'AR')->count());
    }

    public function test_payment_received_posts_exactly_once(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax_amount' => 0, 'amount' => 1000, 'amount_due' => 1000, 'tax_rate_bps' => 0]);
        LedgerService::recordInvoiceIssued($invoice);

        // Model hook already posts on create; an explicit re-call must not duplicate.
        $payment = Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000, 'currency' => 'EUR', 'status' => 'succeeded',
            'provider' => 'offline', 'provider_payment_id' => 'off-1',
        ]);
        LedgerService::recordPaymentReceived($payment);

        $this->assertSame(1, LedgerEntry::where('entry_type', 'payment_received')
            ->where('reference_id', $payment->id)->where('account_code', 'CASH')->count());
    }

    // ── F3: VAT isolation ────────────────────────────────────

    public function test_vat_is_isolated_from_revenue(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax_amount' => 200, 'amount' => 1200, 'amount_due' => 1200]);

        LedgerService::recordInvoiceIssued($invoice);
        $b = LedgerService::trialBalance($this->company->id);

        $this->assertSame(1200.0, $b['AR']);
        $this->assertSame(-1000.0, $b['REVENUE'], 'REVENUE must be HT only.');
        $this->assertSame(-200.0, $b['VAT_PAYABLE'], 'Collected VAT must be isolated.');
        $this->balanced();
    }

    public function test_vat_split_is_proportional_when_wallet_credit_applied(): void
    {
        // gross 1200 (1000 HT + 200 VAT), wallet credit 600 → amount_due 600.
        $invoice = $this->invoice([
            'subtotal' => 1000, 'tax_amount' => 200, 'amount' => 1200,
            'wallet_credit_applied' => 600, 'amount_due' => 600,
        ]);

        LedgerService::recordInvoiceIssued($invoice);
        $b = LedgerService::trialBalance($this->company->id);

        $this->assertSame(600.0, $b['AR']);
        $this->assertSame(-500.0, $b['REVENUE']);   // 600 * 1000/1200
        $this->assertSame(-100.0, $b['VAT_PAYABLE']); // 600 * 200/1200
        $this->balanced();
    }

    public function test_tax_exempt_invoice_has_no_vat_entry(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax_amount' => 0, 'amount' => 1000, 'amount_due' => 1000, 'tax_rate_bps' => 0]);

        LedgerService::recordInvoiceIssued($invoice);
        $b = LedgerService::trialBalance($this->company->id);

        $this->assertSame(-1000.0, $b['REVENUE']);
        $this->assertSame(0.0, $b['VAT_PAYABLE']);
    }

    // ── F1: all payment methods clear AR ─────────────────────

    public function test_non_stripe_payment_clears_ar(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax_amount' => 200, 'amount' => 1200, 'amount_due' => 1200]);
        LedgerService::recordInvoiceIssued($invoice);

        // Offline/manual payment — NOT a Stripe webhook. Must still clear AR.
        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 1200, 'currency' => 'EUR', 'status' => 'succeeded',
            'provider' => 'offline', 'provider_payment_id' => 'off-ar',
        ]);

        $b = LedgerService::trialBalance($this->company->id);
        $this->assertSame(0.0, $b['AR'], 'AR must be cleared by a non-Stripe payment (F1).');
        $this->assertSame(1200.0, $b['CASH']);
        $this->balanced();
    }

    public function test_failed_payment_does_not_post(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax_amount' => 0, 'amount' => 1000, 'amount_due' => 1000, 'tax_rate_bps' => 0]);
        LedgerService::recordInvoiceIssued($invoice);

        Payment::create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000, 'currency' => 'EUR', 'status' => 'failed',
            'provider' => 'offline', 'provider_payment_id' => 'off-fail',
        ]);

        $this->assertSame(0, LedgerEntry::where('entry_type', 'payment_received')->count());
    }
}
