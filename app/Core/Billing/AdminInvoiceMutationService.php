<?php

namespace App\Core\Billing;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-135 D2a: Platform admin invoice mutations.
 *
 * Three operations: mark-paid-offline, void, update-notes.
 * All require finalized invoices. Mark-paid and void use idempotency keys.
 */
class AdminInvoiceMutationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Mark a finalized invoice as paid offline.
     *
     * Creates a Payment(provider=offline). Idempotent: replays return
     * the existing state without duplicating the payment.
     *
     * @return array{replayed: bool, invoice: Invoice}
     */
    public function markPaidOffline(Invoice $invoice, string $idempotencyKey): array
    {
        // Idempotency: already paid with this key?
        if ($invoice->status === 'paid') {
            $existing = Payment::where('company_id', $invoice->company_id)
                ->where('provider', 'offline')
                ->whereJsonContains('metadata->idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return ['replayed' => true, 'invoice' => $invoice];
            }

            throw new RuntimeException('Invoice is already paid.');
        }

        $this->assertFinalized($invoice);
        $this->assertNotVoided($invoice);

        return DB::transaction(function () use ($invoice, $idempotencyKey) {
            $before = ['status' => $invoice->status, 'paid_at' => null];

            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            Payment::create([
                'company_id' => $invoice->company_id,
                'subscription_id' => $invoice->subscription_id,
                'amount' => $invoice->amount_due,
                'currency' => $invoice->currency,
                'status' => 'succeeded',
                'provider' => 'offline',
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);

            $invoice->refresh();

            $this->audit->logPlatform(
                AuditAction::INVOICE_MARKED_PAID,
                'invoice',
                (string) $invoice->id,
                [
                    'severity' => 'warning',
                    'diffBefore' => $before,
                    'diffAfter' => ['status' => 'paid', 'paid_at' => $invoice->paid_at->toIso8601String()],
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $invoice->number,
                        'amount_due' => $invoice->amount_due,
                    ],
                ],
            );

            return ['replayed' => false, 'invoice' => $invoice];
        });
    }

    /**
     * Void a finalized invoice.
     *
     * If wallet_credit_applied > 0, issues a credit note to reverse
     * the wallet debit. Idempotent: replays return the existing state.
     *
     * @return array{replayed: bool, invoice: Invoice}
     */
    public function void(Invoice $invoice, string $idempotencyKey): array
    {
        // Idempotency: already voided?
        if ($invoice->status === 'void') {
            return ['replayed' => true, 'invoice' => $invoice];
        }

        $this->assertFinalized($invoice);

        if ($invoice->status === 'paid') {
            throw new RuntimeException('Cannot void a paid invoice. Refund first.');
        }

        return DB::transaction(function () use ($invoice, $idempotencyKey) {
            $before = ['status' => $invoice->status, 'voided_at' => null];

            $invoice->update([
                'status' => 'void',
                'voided_at' => now(),
            ]);

            // Reverse wallet credit if any was applied
            if ($invoice->wallet_credit_applied > 0) {
                CreditNoteIssuer::issueAndApply(
                    company: $invoice->company,
                    amount: $invoice->wallet_credit_applied,
                    reason: "Void reversal for invoice {$invoice->number}",
                    invoiceId: $invoice->id,
                    actorType: 'platform_admin',
                );
            }

            $invoice->refresh();

            $this->audit->logPlatform(
                AuditAction::INVOICE_VOIDED,
                'invoice',
                (string) $invoice->id,
                [
                    'severity' => 'warning',
                    'diffBefore' => $before,
                    'diffAfter' => ['status' => 'void', 'voided_at' => $invoice->voided_at->toIso8601String()],
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $invoice->number,
                        'wallet_credit_reversed' => $invoice->wallet_credit_applied,
                    ],
                ],
            );

            return ['replayed' => false, 'invoice' => $invoice];
        });
    }

    /**
     * Update notes on a finalized invoice.
     *
     * @return array{changed: bool, invoice: Invoice}
     */
    public function updateNotes(Invoice $invoice, ?string $notes): array
    {
        $this->assertFinalized($invoice);
        $this->assertNotVoided($invoice);

        $before = $invoice->notes;

        if ($before === $notes) {
            return ['changed' => false, 'invoice' => $invoice];
        }

        $invoice->update(['notes' => $notes]);
        $invoice->refresh();

        $this->audit->logPlatform(
            AuditAction::INVOICE_NOTES_UPDATED,
            'invoice',
            (string) $invoice->id,
            [
                'diffBefore' => ['notes' => $before],
                'diffAfter' => ['notes' => $notes],
                'metadata' => ['invoice_number' => $invoice->number],
            ],
        );

        return ['changed' => true, 'invoice' => $invoice];
    }

    // ── Guards ──────────────────────────────────────────────

    private function assertFinalized(Invoice $invoice): void
    {
        if (! $invoice->isFinalized()) {
            throw new RuntimeException('Invoice must be finalized.');
        }
    }

    private function assertNotVoided(Invoice $invoice): void
    {
        if ($invoice->voided_at !== null) {
            throw new RuntimeException('Invoice is already voided.');
        }
    }
}
