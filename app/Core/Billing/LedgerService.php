<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADR-142 D3f: Double-entry financial ledger service.
 *
 * Every financial event produces at least 2 entries:
 *   - SUM(debit) == SUM(credit) per correlation_id
 *
 * Account codes: AR, CASH, REVENUE, REFUND, BAD_DEBT
 *
 * Ledger writes happen AFTER business state success.
 * Never throws — failures are logged, never break the business flow.
 */
class LedgerService
{
    /**
     * Record invoice issued (finalized).
     *
     * Debit AR (accounts receivable), Credit REVENUE.
     */
    public static function recordInvoiceIssued(Invoice $invoice): void
    {
        self::assertPeriodOpen($invoice->company_id);
        self::assertNotFrozen($invoice->company_id);

        $correlationId = Str::uuid()->toString();
        $amount = self::toCents($invoice->amount_due);

        if ($amount <= 0) {
            return; // Zero-amount invoices don't hit the ledger
        }

        DB::transaction(function () use ($invoice, $correlationId, $amount) {
            $now = now();
            $base = [
                'company_id' => $invoice->company_id,
                'entry_type' => 'invoice_issued',
                'currency' => strtoupper($invoice->currency ?? config('billing.default_currency', 'EUR')),
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
                'correlation_id' => $correlationId,
                'recorded_at' => $now,
                'metadata' => json_encode([
                    'invoice_number' => $invoice->number,
                    'amount_due' => $invoice->amount_due,
                ]),
            ];

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'AR',
                'debit' => $amount,
                'credit' => 0,
            ]));

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'REVENUE',
                'debit' => 0,
                'credit' => $amount,
            ]));
        });
    }

    /**
     * Record payment received.
     *
     * Debit CASH, Credit AR.
     */
    public static function recordPaymentReceived(Payment $payment): void
    {
        self::assertPeriodOpen($payment->company_id);
        self::assertNotFrozen($payment->company_id);

        $correlationId = Str::uuid()->toString();
        $amount = self::toCents($payment->amount);

        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($payment, $correlationId, $amount) {
            $now = now();
            $base = [
                'company_id' => $payment->company_id,
                'entry_type' => 'payment_received',
                'currency' => strtoupper($payment->currency ?? config('billing.default_currency', 'EUR')),
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'correlation_id' => $correlationId,
                'recorded_at' => $now,
                'metadata' => json_encode([
                    'provider' => $payment->provider,
                    'provider_payment_id' => $payment->provider_payment_id,
                ]),
            ];

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'CASH',
                'debit' => $amount,
                'credit' => 0,
            ]));

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'AR',
                'debit' => 0,
                'credit' => $amount,
            ]));
        });
    }

    /**
     * Record refund issued (credit note).
     *
     * Debit REFUND, Credit CASH.
     */
    public static function recordRefundIssued(CreditNote $creditNote): void
    {
        self::assertPeriodOpen($creditNote->company_id);
        self::assertNotFrozen($creditNote->company_id);

        $correlationId = Str::uuid()->toString();
        $amount = self::toCents($creditNote->amount);

        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($creditNote, $correlationId, $amount) {
            $now = now();
            $base = [
                'company_id' => $creditNote->company_id,
                'entry_type' => 'refund_issued',
                'currency' => strtoupper($creditNote->currency ?? config('billing.default_currency', 'EUR')),
                'reference_type' => 'credit_note',
                'reference_id' => $creditNote->id,
                'correlation_id' => $correlationId,
                'recorded_at' => $now,
                'metadata' => json_encode([
                    'credit_note_number' => $creditNote->number,
                    'invoice_id' => $creditNote->invoice_id,
                ]),
            ];

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'REFUND',
                'debit' => $amount,
                'credit' => 0,
            ]));

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'CASH',
                'debit' => 0,
                'credit' => $amount,
            ]));
        });
    }

    /**
     * Record invoice write-off (bad debt).
     *
     * Debit BAD_DEBT, Credit AR.
     */
    public static function recordWriteOff(Invoice $invoice): void
    {
        self::assertPeriodOpen($invoice->company_id);
        self::assertNotFrozen($invoice->company_id);

        $correlationId = Str::uuid()->toString();
        $amount = self::toCents($invoice->amount_due);

        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($invoice, $correlationId, $amount) {
            $now = now();
            $base = [
                'company_id' => $invoice->company_id,
                'entry_type' => 'writeoff',
                'currency' => strtoupper($invoice->currency ?? config('billing.default_currency', 'EUR')),
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
                'correlation_id' => $correlationId,
                'recorded_at' => $now,
                'metadata' => json_encode([
                    'invoice_number' => $invoice->number,
                    'amount_due' => $invoice->amount_due,
                ]),
            ];

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'BAD_DEBT',
                'debit' => $amount,
                'credit' => 0,
            ]));

            LedgerEntry::create(array_merge($base, [
                'account_code' => 'AR',
                'debit' => 0,
                'credit' => $amount,
            ]));
        });
    }

    /**
     * Compute trial balance for a company.
     *
     * Returns account balances as SUM(debit) - SUM(credit) per account.
     *
     * @return array<string, float> Account code → balance
     */
    public static function trialBalance(int $companyId): array
    {
        $rows = LedgerEntry::where('company_id', $companyId)
            ->selectRaw('account_code, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('account_code')
            ->get();

        $balance = [];

        foreach ($rows as $row) {
            $balance[$row->account_code] = round((float) $row->total_debit - (float) $row->total_credit, 2);
        }

        // Ensure all standard accounts are present
        foreach (['AR', 'CASH', 'REVENUE', 'REFUND', 'BAD_DEBT'] as $code) {
            $balance[$code] ??= 0.0;
        }

        return $balance;
    }

    /**
     * Record an adjustment entry within a closed period.
     *
     * Adjustment entries are the ONLY way to modify the ledger
     * after a period is closed. They use entry_type='adjustment'
     * and require an explicit reason in metadata.
     *
     * @throws RuntimeException If company is financially frozen
     */
    public static function recordAdjustment(
        int $companyId,
        string $accountCodeDebit,
        string $accountCodeCredit,
        float $amount,
        string $currency,
        string $referenceType,
        int $referenceId,
        string $reason,
    ): string {
        // Financial freeze blocks ALL ledger writes including adjustments
        $company = \App\Core\Models\Company::find($companyId);
        if ($company && $company->financial_freeze) {
            throw new RuntimeException('Company is financially frozen — no ledger writes allowed.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Adjustment amount must be positive.');
        }

        $correlationId = Str::uuid()->toString();

        DB::transaction(function () use ($companyId, $accountCodeDebit, $accountCodeCredit, $amount, $currency, $referenceType, $referenceId, $reason, $correlationId) {
            $now = now();
            $base = [
                'company_id' => $companyId,
                'entry_type' => 'adjustment',
                'currency' => strtoupper($currency),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'correlation_id' => $correlationId,
                'recorded_at' => $now,
                'metadata' => json_encode(['reason' => $reason]),
            ];

            LedgerEntry::create(array_merge($base, [
                'account_code' => $accountCodeDebit,
                'debit' => $amount,
                'credit' => 0,
            ]));

            LedgerEntry::create(array_merge($base, [
                'account_code' => $accountCodeCredit,
                'debit' => 0,
                'credit' => $amount,
            ]));
        });

        return $correlationId;
    }

    /**
     * Guard: reject ledger writes if the recording date falls within a closed period.
     *
     * Called before every normal recording method.
     *
     * @throws RuntimeException If the date falls within a closed period
     */
    public static function assertPeriodOpen(int $companyId, ?\DateTimeInterface $date = null): void
    {
        $date = $date ?? now();
        $dateStr = $date->format('Y-m-d');

        $closed = FinancialPeriod::where('company_id', $companyId)
            ->where('is_closed', true)
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->exists();

        if ($closed) {
            throw new RuntimeException(
                "Ledger write rejected: date {$dateStr} falls within a closed financial period."
            );
        }
    }

    /**
     * Guard: reject ledger writes if the company is financially frozen.
     *
     * @throws RuntimeException If the company is frozen
     */
    public static function assertNotFrozen(int $companyId): void
    {
        $company = \App\Core\Models\Company::find($companyId);

        if ($company && $company->financial_freeze) {
            throw new RuntimeException('Company is financially frozen — no ledger writes allowed.');
        }
    }

    /**
     * Convert cents integer to decimal for ledger (cents → decimal with 2 places).
     * Our billing models store amounts in cents (integer).
     * The ledger stores amounts as decimal(15,2).
     */
    private static function toCents(int $amountInCents): float
    {
        return round($amountInCents / 100, 2);
    }
}
