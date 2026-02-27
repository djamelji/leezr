<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyWallet;
use App\Core\Billing\CreditNoteIssuer;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->company = Company::create([
            'name' => 'Invoice Co',
            'slug' => 'invoice-co',
        ]);
    }

    // ── Draft + Finalize ──

    public function test_create_draft_and_finalize(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company, periodStart: '2026-03-01', periodEnd: '2026-03-31');

        $this->assertEquals('draft', $invoice->status);
        $this->assertNull($invoice->number);

        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan - March 2026', 2900);
        InvoiceIssuer::addLine($invoice, 'addon', 'Logistics module', 1500, moduleKey: 'logistics.shipments');

        $invoice = InvoiceIssuer::finalize($invoice);

        $this->assertEquals('open', $invoice->status);
        $this->assertNotNull($invoice->number);
        $this->assertNotNull($invoice->finalized_at);
        $this->assertEquals(4400, $invoice->subtotal);
        $this->assertEquals(4400, $invoice->amount); // no tax in none mode
        $this->assertEquals(0, $invoice->tax_amount);
        $this->assertEquals(4400, $invoice->amount_due);
        $this->assertEquals(0, $invoice->wallet_credit_applied);
        $this->assertNotNull($invoice->billing_snapshot);
    }

    // ── Wallet-first: auto-apply credit ──

    public function test_finalize_applies_wallet_credit(): void
    {
        WalletLedger::credit($this->company, 1000, 'admin_adjustment');

        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);

        $invoice = InvoiceIssuer::finalize($invoice);

        $this->assertEquals(2900, $invoice->amount);
        $this->assertEquals(1000, $invoice->wallet_credit_applied);
        $this->assertEquals(1900, $invoice->amount_due);
        $this->assertEquals('open', $invoice->status);
        $this->assertEquals(0, WalletLedger::balance($this->company));
    }

    public function test_finalize_fully_paid_by_wallet(): void
    {
        WalletLedger::credit($this->company, 5000, 'admin_adjustment');

        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Starter plan', 0);
        InvoiceIssuer::addLine($invoice, 'addon', 'Small addon', 2000);

        $invoice = InvoiceIssuer::finalize($invoice);

        $this->assertEquals(2000, $invoice->amount);
        $this->assertEquals(2000, $invoice->wallet_credit_applied);
        $this->assertEquals(0, $invoice->amount_due);
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertEquals(3000, WalletLedger::balance($this->company));
    }

    // ── Tax ──

    public function test_finalize_with_exclusive_tax(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['tax_mode' => 'exclusive', 'default_tax_rate_bps' => 2000]);

        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 10000);

        $invoice = InvoiceIssuer::finalize($invoice);

        $this->assertEquals(10000, $invoice->subtotal);
        $this->assertEquals(2000, $invoice->tax_amount);
        $this->assertEquals(12000, $invoice->amount);
        $this->assertEquals(12000, $invoice->amount_due);
    }

    // ── Immutability ──

    public function test_cannot_add_line_after_finalize(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot add lines to a finalized invoice.');

        InvoiceIssuer::addLine($invoice, 'addon', 'Extra', 500);
    }

    public function test_cannot_finalize_twice(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);
        InvoiceIssuer::finalize($invoice);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invoice is already finalized.');

        InvoiceIssuer::finalize($invoice);
    }

    public function test_cannot_finalize_empty_invoice(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot finalize invoice with no lines.');

        InvoiceIssuer::finalize($invoice);
    }

    // ── Subtotal invariant ──

    public function test_subtotal_equals_sum_of_lines(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Plan', 2900);
        InvoiceIssuer::addLine($invoice, 'addon', 'Addon A', 1500);
        InvoiceIssuer::addLine($invoice, 'addon', 'Addon B', 800);

        $invoice = InvoiceIssuer::finalize($invoice);

        $lineSum = $invoice->lines->sum('amount');
        $this->assertEquals($lineSum, $invoice->subtotal);
        $this->assertEquals(5200, $invoice->subtotal);
    }

    // ── Credit Note → Wallet ──

    public function test_credit_note_issue_and_apply(): void
    {
        $cn = CreditNoteIssuer::issueAndApply(
            company: $this->company,
            amount: 3000,
            reason: 'Goodwill credit',
        );

        $this->assertEquals('applied', $cn->status);
        $this->assertNotNull($cn->number);
        $this->assertNotNull($cn->wallet_transaction_id);
        $this->assertNotNull($cn->issued_at);
        $this->assertNotNull($cn->applied_at);
        $this->assertEquals(3000, WalletLedger::balance($this->company));
    }

    public function test_credit_note_linked_to_invoice(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro', 5000);
        $invoice = InvoiceIssuer::finalize($invoice);

        $cn = CreditNoteIssuer::issueAndApply(
            company: $this->company,
            amount: 5000,
            reason: 'Full refund',
            invoiceId: $invoice->id,
        );

        $this->assertEquals($invoice->id, $cn->invoice_id);
        $this->assertTrue($cn->isApplied());
    }

    // ── Credit note lifecycle guards ──

    public function test_cannot_apply_draft_credit_note(): void
    {
        $cn = CreditNoteIssuer::createDraft($this->company, 1000, 'Test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Credit note must be in 'issued' status to apply.");

        CreditNoteIssuer::apply($cn);
    }

    public function test_cannot_issue_non_draft_credit_note(): void
    {
        $cn = CreditNoteIssuer::createDraft($this->company, 1000, 'Test');
        $cn = CreditNoteIssuer::issue($cn);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Credit note must be in 'draft' status to issue.");

        CreditNoteIssuer::issue($cn);
    }

    // ── Sequential numbering across invoices ──

    public function test_invoice_numbers_sequential(): void
    {
        $i1 = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($i1, 'plan', 'Plan', 100);
        $i1 = InvoiceIssuer::finalize($i1);

        $i2 = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($i2, 'plan', 'Plan', 200);
        $i2 = InvoiceIssuer::finalize($i2);

        $year = now()->format('Y');
        $this->assertEquals("INV-{$year}-000001", $i1->number);
        $this->assertEquals("INV-{$year}-000002", $i2->number);
    }

    // ── Billing snapshot frozen ──

    public function test_billing_snapshot_frozen_at_finalization(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Plan', 100);
        $invoice = InvoiceIssuer::finalize($invoice);

        $snapshot = $invoice->billing_snapshot;
        $this->assertEquals('Invoice Co', $snapshot['company_name']);
        $this->assertArrayHasKey('company_legal_name', $snapshot);
        $this->assertArrayHasKey('market_key', $snapshot);
    }
}
