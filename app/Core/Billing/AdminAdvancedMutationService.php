<?php

namespace App\Core\Billing;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ADR-136 D2c: Advanced platform admin invoice mutations.
 *
 * Five operations: refund, retry-payment, dunning-transition,
 * manual credit-note, write-off.
 * All require finalized invoices, idempotency keys, and audit logging.
 */
class AdminAdvancedMutationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issue a refund credit note for a paid invoice.
     *
     * Creates a CreditNote in 'issued' status (no wallet apply in V1).
     * Cumulative guard: total refund credit notes <= invoice amount.
     *
     * @return array{replayed: bool, credit_note: CreditNote}
     */
    public function refund(Invoice $invoice, int $amount, string $reason, string $idempotencyKey): array
    {
        $this->assertFinalized($invoice);

        // Idempotency: check for existing refund CN with this key
        $existing = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return ['replayed' => true, 'credit_note' => $existing];
        }

        if ($invoice->status !== 'paid') {
            throw new RuntimeException('Only paid invoices can be refunded.');
        }

        // ADR-143 D3g: Financial freeze guard
        $company = $invoice->company;
        if ($company && $company->financial_freeze) {
            throw new RuntimeException('Company is financially frozen — refunds are blocked.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be positive.');
        }

        if ($amount > $invoice->amount) {
            throw new RuntimeException('Refund amount exceeds invoice total.');
        }

        // Cumulative guard: sum of existing refund CNs + this amount <= invoice amount
        $existingRefunds = (int) CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->type', 'refund')
            ->sum('amount');

        if ($existingRefunds + $amount > $invoice->amount) {
            throw new RuntimeException('Cumulative refund amount exceeds invoice total.');
        }

        // Provider-first refund: if invoice was paid via external provider, chain refund there first
        $providerPayment = Payment::where('invoice_id', $invoice->id)
            ->where('status', 'succeeded')
            ->whereNotNull('provider_payment_id')
            ->where('provider', '!=', 'internal')
            ->first();

        $providerRefundId = null;

        if ($providerPayment) {
            $adapter = PaymentGatewayManager::adapterFor($providerPayment->provider);

            if ($adapter) {
                $refundResult = $adapter->refund($providerPayment->provider_payment_id, $amount, [
                    'invoice_id' => (string) $invoice->id,
                    'company_id' => (string) $invoice->company_id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $providerRefundId = $refundResult['provider_refund_id'] ?? null;
            }
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $idempotencyKey, $providerPayment, $providerRefundId) {
            $creditNote = CreditNoteIssuer::createDraft(
                company: $invoice->company,
                amount: $amount,
                reason: $reason,
                invoiceId: $invoice->id,
                metadata: [
                    'idempotency_key' => $idempotencyKey,
                    'type' => 'refund',
                    'provider_refund_id' => $providerRefundId,
                    'provider_payment_id' => $providerPayment?->provider_payment_id,
                ],
            );

            $creditNote = CreditNoteIssuer::issue($creditNote);

            // Ledger: record refund issued (ADR-142 D3f)
            try {
                LedgerService::recordRefundIssued($creditNote);
            } catch (\Throwable $e) {
                Log::warning('[ledger] refund recording failed', [
                    'credit_note_id' => $creditNote->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->audit->logPlatform(
                AuditAction::BILLING_REFUND,
                'invoice',
                (string) $invoice->id,
                [
                    'severity' => 'critical',
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $invoice->number,
                        'credit_note_id' => $creditNote->id,
                        'credit_note_number' => $creditNote->number,
                        'amount' => $amount,
                        'reason' => $reason,
                        'provider_refund_id' => $providerRefundId,
                        'provider_payment_id' => $providerPayment?->provider_payment_id,
                    ],
                ],
            );

            return ['replayed' => false, 'credit_note' => $creditNote];
        });
    }

    /**
     * Force a dunning retry on an overdue invoice.
     *
     * Delegates to DunningEngine::retrySingleInvoice() which handles
     * wallet payment, retry scheduling, and exhaustion logic.
     *
     * @return array{result: string, invoice: Invoice}
     */
    public function retryPayment(Invoice $invoice, string $idempotencyKey): array
    {
        $this->assertFinalized($invoice);

        if ($invoice->status !== 'overdue') {
            throw new RuntimeException('Only overdue invoices can be retried.');
        }

        $result = DunningEngine::retrySingleInvoice($invoice);

        $invoice->refresh();

        $this->audit->logPlatform(
            AuditAction::DUNNING_FORCE_RETRY,
            'invoice',
            (string) $invoice->id,
            [
                'severity' => 'warning',
                'metadata' => [
                    'idempotency_key' => $idempotencyKey,
                    'invoice_number' => $invoice->number,
                    'result' => $result,
                ],
            ],
        );

        return ['result' => $result, 'invoice' => $invoice];
    }

    /**
     * Force a dunning state transition.
     *
     * Allowed transitions:
     *   - open → overdue (schedules retry, transitions subscription to past_due)
     *   - overdue → uncollectible (applies failure action)
     *
     * @return array{replayed: bool, invoice: Invoice}
     */
    public function forceDunningTransition(Invoice $invoice, string $targetStatus, string $idempotencyKey): array
    {
        $this->assertFinalized($invoice);

        // Idempotency: already in target status
        if ($invoice->status === $targetStatus) {
            return ['replayed' => true, 'invoice' => $invoice];
        }

        $allowed = [
            'open' => 'overdue',
            'overdue' => 'uncollectible',
        ];

        if (!isset($allowed[$invoice->status]) || $allowed[$invoice->status] !== $targetStatus) {
            throw new RuntimeException(
                "Invalid dunning transition: {$invoice->status} → {$targetStatus}."
            );
        }

        return DB::transaction(function () use ($invoice, $targetStatus, $idempotencyKey) {
            $before = ['status' => $invoice->status];

            if ($targetStatus === 'overdue') {
                $policy = PlatformBillingPolicy::instance();
                $retryIntervals = $policy->retry_intervals_days ?? [1, 3, 7];
                $nextRetryDays = $retryIntervals[0] ?? 1;

                $invoice->update([
                    'status' => 'overdue',
                    'next_retry_at' => now()->addDays($nextRetryDays),
                ]);

                // Transition subscription to past_due
                if ($invoice->subscription_id) {
                    Subscription::where('id', $invoice->subscription_id)
                        ->whereIn('status', ['active', 'trialing'])
                        ->update(['status' => 'past_due']);
                }
            } else {
                // overdue → uncollectible
                $invoice->update([
                    'status' => 'uncollectible',
                    'next_retry_at' => null,
                ]);

                // Ledger: record write-off (ADR-142 D3f)
                try {
                    LedgerService::recordWriteOff($invoice);
                } catch (\Throwable $e) {
                    Log::warning('[ledger] forced dunning writeoff recording failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $policy = PlatformBillingPolicy::instance();
                DunningEngine::applyFailureAction($invoice->company, $policy);
            }

            $invoice->refresh();

            $this->audit->logPlatform(
                AuditAction::INVOICE_DUNNING_FORCED,
                'invoice',
                (string) $invoice->id,
                [
                    'severity' => 'critical',
                    'diffBefore' => $before,
                    'diffAfter' => ['status' => $targetStatus],
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $invoice->number,
                        'target_status' => $targetStatus,
                    ],
                ],
            );

            return ['replayed' => false, 'invoice' => $invoice];
        });
    }

    /**
     * Issue a manual credit note for an invoice.
     *
     * Optionally applies the credit to the company wallet.
     *
     * @return array{replayed: bool, credit_note: CreditNote}
     */
    public function issueCreditNote(Invoice $invoice, int $amount, string $reason, bool $applyToWallet, string $idempotencyKey): array
    {
        $this->assertFinalized($invoice);
        $this->assertNotVoided($invoice);

        // Idempotency: check for existing CN with this key
        $existing = CreditNote::where('invoice_id', $invoice->id)
            ->whereJsonContains('metadata->idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return ['replayed' => true, 'credit_note' => $existing];
        }

        if ($amount <= 0) {
            throw new RuntimeException('Credit note amount must be positive.');
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $applyToWallet, $idempotencyKey) {
            $creditNote = CreditNoteIssuer::createDraft(
                company: $invoice->company,
                amount: $amount,
                reason: $reason,
                invoiceId: $invoice->id,
                metadata: ['idempotency_key' => $idempotencyKey, 'type' => 'manual'],
            );

            $creditNote = CreditNoteIssuer::issue($creditNote);

            if ($applyToWallet) {
                $creditNote = CreditNoteIssuer::apply($creditNote, 'platform_admin');
            }

            $this->audit->logPlatform(
                AuditAction::CREDIT_NOTE_MANUAL,
                'invoice',
                (string) $invoice->id,
                [
                    'severity' => 'warning',
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $invoice->number,
                        'credit_note_id' => $creditNote->id,
                        'credit_note_number' => $creditNote->number,
                        'amount' => $amount,
                        'reason' => $reason,
                        'applied_to_wallet' => $applyToWallet,
                    ],
                ],
            );

            return ['replayed' => false, 'credit_note' => $creditNote];
        });
    }

    /**
     * Write off an overdue invoice as uncollectible.
     *
     * Pure accounting operation — does NOT apply failure action.
     * This is the key difference from forceDunningTransition(overdue→uncollectible).
     *
     * @return array{replayed: bool, invoice: Invoice}
     */
    public function writeOff(Invoice $invoice, string $idempotencyKey): array
    {
        $this->assertFinalized($invoice);

        // Idempotency: already uncollectible
        if ($invoice->status === 'uncollectible') {
            return ['replayed' => true, 'invoice' => $invoice];
        }

        if ($invoice->status !== 'overdue') {
            throw new RuntimeException('Only overdue invoices can be written off.');
        }

        // ADR-143 D3g: Writeoff threshold guard
        $threshold = (int) config('billing.writeoff_threshold', 0);
        if ($threshold > 0 && $invoice->amount_due > $threshold) {
            throw new RuntimeException(
                "Write-off amount ({$invoice->amount_due}) exceeds threshold ({$threshold})."
            );
        }

        // ADR-143 D3g: Financial freeze guard
        $company = $invoice->company;
        if ($company && $company->financial_freeze) {
            throw new RuntimeException('Company is financially frozen — write-offs are blocked.');
        }

        return DB::transaction(function () use ($invoice, $idempotencyKey) {
            $before = ['status' => $invoice->status];

            $invoice->update([
                'status' => 'uncollectible',
                'next_retry_at' => null,
            ]);

            $invoice->refresh();

            // Ledger: record write-off (ADR-142 D3f)
            try {
                LedgerService::recordWriteOff($invoice);
            } catch (\Throwable $e) {
                Log::warning('[ledger] writeoff recording failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->audit->logPlatform(
                AuditAction::INVOICE_WRITTEN_OFF,
                'invoice',
                (string) $invoice->id,
                [
                    'severity' => 'critical',
                    'diffBefore' => $before,
                    'diffAfter' => ['status' => 'uncollectible'],
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $invoice->number,
                    ],
                ],
            );

            return ['replayed' => false, 'invoice' => $invoice];
        });
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
