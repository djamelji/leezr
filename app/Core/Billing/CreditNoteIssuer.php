<?php

namespace App\Core\Billing;

use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues credit notes and applies them to wallets.
 *
 * Lifecycle: draft → issued → applied
 * Constraint: "applied" status requires non-null wallet_transaction_id.
 */
class CreditNoteIssuer
{
    /**
     * Create a draft credit note.
     */
    public static function createDraft(
        Company $company,
        int $amount,
        string $reason,
        ?int $invoiceId = null,
        ?array $metadata = null,
    ): CreditNote {
        if ($amount <= 0) {
            throw new RuntimeException('Credit note amount must be positive.');
        }

        $wallet = WalletLedger::ensureWallet($company);

        return CreditNote::create([
            'company_id' => $company->id,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'reason' => $reason,
            'status' => 'draft',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Issue the credit note (assigns number, freezes snapshot).
     */
    public static function issue(CreditNote $creditNote): CreditNote
    {
        if ($creditNote->status !== 'draft') {
            throw new RuntimeException("Credit note must be in 'draft' status to issue.");
        }

        $number = InvoiceNumbering::nextCreditNoteNumber();
        $company = $creditNote->company;

        $creditNote->update([
            'number' => $number,
            'status' => 'issued',
            'issued_at' => now(),
            'billing_snapshot' => [
                'company_name' => $company->name,
                'company_legal_name' => $company->legal_name ?? $company->name,
                'market_key' => $company->market_key,
            ],
        ]);

        return $creditNote->fresh();
    }

    /**
     * Apply the credit note: credit the wallet.
     * Constraint: after this, wallet_transaction_id must be non-null.
     */
    public static function apply(CreditNote $creditNote, ?string $actorType = 'system', ?int $actorId = null): CreditNote
    {
        if ($creditNote->status !== 'issued') {
            throw new RuntimeException("Credit note must be in 'issued' status to apply.");
        }

        return DB::transaction(function () use ($creditNote, $actorType, $actorId) {
            $company = $creditNote->company;

            $transaction = WalletLedger::credit(
                company: $company,
                amount: $creditNote->amount,
                sourceType: 'credit_note',
                sourceId: $creditNote->id,
                description: "Credit note {$creditNote->number}",
                actorType: $actorType,
                actorId: $actorId,
                idempotencyKey: "credit-note-{$creditNote->id}",
            );

            $creditNote->update([
                'status' => 'applied',
                'applied_at' => now(),
                'wallet_transaction_id' => $transaction->id,
            ]);

            return $creditNote->fresh();
        });
    }

    /**
     * Convenience: create, issue, and apply in one call.
     */
    public static function issueAndApply(
        Company $company,
        int $amount,
        string $reason,
        ?int $invoiceId = null,
        ?string $actorType = 'system',
        ?int $actorId = null,
    ): CreditNote {
        $cn = static::createDraft($company, $amount, $reason, $invoiceId);
        $cn = static::issue($cn);

        return static::apply($cn, $actorType, $actorId);
    }
}
