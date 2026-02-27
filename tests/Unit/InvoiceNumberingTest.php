<?php

namespace Tests\Unit;

use App\Core\Billing\InvoiceNumbering;
use App\Core\Billing\PlatformBillingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    public function test_invoice_numbers_are_sequential(): void
    {
        $n1 = InvoiceNumbering::nextInvoiceNumber();
        $n2 = InvoiceNumbering::nextInvoiceNumber();
        $n3 = InvoiceNumbering::nextInvoiceNumber();

        $year = now()->format('Y');

        $this->assertEquals("INV-{$year}-000001", $n1);
        $this->assertEquals("INV-{$year}-000002", $n2);
        $this->assertEquals("INV-{$year}-000003", $n3);
    }

    public function test_credit_note_numbers_are_sequential(): void
    {
        $n1 = InvoiceNumbering::nextCreditNoteNumber();
        $n2 = InvoiceNumbering::nextCreditNoteNumber();

        $year = now()->format('Y');

        $this->assertEquals("CN-{$year}-000001", $n1);
        $this->assertEquals("CN-{$year}-000002", $n2);
    }

    public function test_invoice_and_credit_note_sequences_are_independent(): void
    {
        $inv = InvoiceNumbering::nextInvoiceNumber();
        $cn = InvoiceNumbering::nextCreditNoteNumber();
        $inv2 = InvoiceNumbering::nextInvoiceNumber();

        $year = now()->format('Y');

        $this->assertEquals("INV-{$year}-000001", $inv);
        $this->assertEquals("CN-{$year}-000001", $cn);
        $this->assertEquals("INV-{$year}-000002", $inv2);
    }

    public function test_custom_prefix(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['invoice_prefix' => 'FA']);

        $n1 = InvoiceNumbering::nextInvoiceNumber();
        $year = now()->format('Y');

        $this->assertEquals("FA-{$year}-000001", $n1);
    }

    public function test_policy_next_number_increments(): void
    {
        InvoiceNumbering::nextInvoiceNumber();
        InvoiceNumbering::nextInvoiceNumber();

        $policy = PlatformBillingPolicy::instance();
        $this->assertEquals(3, $policy->fresh()->invoice_next_number);
    }
}
