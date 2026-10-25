<?php

namespace Tests\Feature;

use App\Core\Billing\AdminAdvancedMutationService;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-631c / BILL-010 — the prohibition on crediting a VOIDED invoice must be enforced by the backend
 * service (AdminAdvancedMutationService), not only by frontend button-gating. The guard uses
 * voided_at (a timestamp, status-literal independent). This pins: forbidden mutation throws AND leaves
 * the invoice + ledger + credit-note table fully intact (no partial financial side effect).
 */
class CreditNoteVoidProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
    }

    private function company(): Company
    {
        return Company::create(['name' => 'CN Co', 'slug' => 'cn-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function invoice(Company $c, string $status, bool $voided = false, bool $finalized = true): Invoice
    {
        return Invoice::create([
            'company_id' => $c->id, 'number' => 'INV-CN-'.uniqid(), 'subtotal' => 1000, 'tax_amount' => 200,
            'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => $status === 'paid' ? 0 : 1200,
            'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => $status,
            'issued_at' => now(), 'finalized_at' => $finalized ? now() : null,
            'voided_at' => $voided ? now() : null, 'due_at' => now()->addMonth(),
        ]);
    }

    private function service(): AdminAdvancedMutationService
    {
        return app(AdminAdvancedMutationService::class);
    }

    public function test_cannot_issue_credit_note_on_voided_invoice_and_nothing_changes(): void
    {
        $c = $this->company();
        $inv = $this->invoice($c, 'void', voided: true);
        $ledgerBefore = LedgerEntry::count();
        $snapshot = $inv->fresh()->toArray(); // DB baseline (same serialization as the post-check)

        try {
            $this->service()->issueCreditNote($inv, 500, 'test', true, 'idem-void-1');
            $this->fail('Expected a RuntimeException crediting a voided invoice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('void', $e->getMessage());
        }

        // Full preservation: no credit note, no ledger movement, invoice byte-for-byte unchanged.
        $this->assertSame(0, CreditNote::where('invoice_id', $inv->id)->count(), 'no credit note created');
        $this->assertSame($ledgerBefore, LedgerEntry::count(), 'no ledger entry created');
        $this->assertEquals($snapshot, $inv->fresh()->toArray(), 'invoice must be untouched');
    }

    public function test_cannot_issue_credit_note_on_non_finalized_invoice(): void
    {
        $c = $this->company();
        $inv = $this->invoice($c, 'draft', finalized: false);

        $this->expectException(RuntimeException::class);
        try {
            $this->service()->issueCreditNote($inv, 500, 'test', false, 'idem-draft-1');
        } finally {
            $this->assertSame(0, CreditNote::where('invoice_id', $inv->id)->count());
        }
    }

    public function test_cannot_refund_a_voided_invoice(): void
    {
        $c = $this->company();
        $inv = $this->invoice($c, 'void', voided: true);
        $ledgerBefore = LedgerEntry::count();

        try {
            $this->service()->refund($inv, 500, 'test', 'idem-refund-void');
            $this->fail('Expected a RuntimeException refunding a voided invoice.');
        } catch (RuntimeException $e) {
            // refund() requires status === 'paid'; a void invoice is rejected before any write.
            $this->assertStringContainsStringIgnoringCase('paid', $e->getMessage());
        }

        $this->assertSame(0, CreditNote::where('invoice_id', $inv->id)->count());
        $this->assertSame($ledgerBefore, LedgerEntry::count());
    }
}
