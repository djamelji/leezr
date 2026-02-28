<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CompanyWallet;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only queries for platform billing administration.
 * Cross-company visibility — requires view_billing permission.
 */
class PlatformBillingReadService
{
    /**
     * All invoices across all companies, paginated with filters.
     *
     * Filters: company_id, status, from, to
     */
    public static function invoices(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Invoice::with('company:id,name,slug')
            ->whereNotNull('finalized_at')
            ->orderByDesc('issued_at');

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['from'])) {
            $query->where('issued_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->where('issued_at', '<=', $filters['to']);
        }

        return $query->paginate(min($perPage, 100));
    }

    /**
     * Single invoice detail (platform-level, no company filter).
     */
    public static function invoiceDetail(int $invoiceId): ?array
    {
        $invoice = Invoice::with(['lines', 'creditNotes', 'company:id,name,slug'])
            ->where('id', $invoiceId)
            ->first();

        if (!$invoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'company' => $invoice->company ? [
                'id' => $invoice->company->id,
                'name' => $invoice->company->name,
                'slug' => $invoice->company->slug,
            ] : null,
            'status' => $invoice->status,
            'amount' => $invoice->amount,
            'subtotal' => $invoice->subtotal,
            'tax_amount' => $invoice->tax_amount,
            'tax_rate_bps' => $invoice->tax_rate_bps,
            'wallet_credit_applied' => $invoice->wallet_credit_applied,
            'amount_due' => $invoice->amount_due,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'issued_at' => $invoice->issued_at?->toISOString(),
            'due_at' => $invoice->due_at?->toISOString(),
            'paid_at' => $invoice->paid_at?->toISOString(),
            'finalized_at' => $invoice->finalized_at?->toISOString(),
            'voided_at' => $invoice->voided_at?->toISOString(),
            'retry_count' => $invoice->retry_count,
            'next_retry_at' => $invoice->next_retry_at?->toISOString(),
            'billing_snapshot' => $invoice->billing_snapshot,
            'lines' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'amount' => $line->amount,
            ])->toArray(),
            'credit_notes' => $invoice->creditNotes->map(fn ($cn) => [
                'id' => $cn->id,
                'number' => $cn->number,
                'amount' => $cn->amount,
                'status' => $cn->status,
                'reason' => $cn->reason,
                'issued_at' => $cn->issued_at?->toISOString(),
                'applied_at' => $cn->applied_at?->toISOString(),
            ])->toArray(),
            'payments' => Payment::where('invoice_id', $invoice->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'provider' => $p->provider,
                    'provider_payment_id' => $p->provider_payment_id,
                    'created_at' => $p->created_at->toISOString(),
                ])->toArray(),
            'ledger_entries' => LedgerEntry::where('company_id', $invoice->company_id)
                ->where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)
                ->orderByDesc('recorded_at')
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'entry_type' => $e->entry_type,
                    'account_code' => $e->account_code,
                    'debit' => $e->debit,
                    'credit' => $e->credit,
                    'currency' => $e->currency,
                    'correlation_id' => $e->correlation_id,
                    'recorded_at' => $e->recorded_at->toISOString(),
                ])->toArray(),
        ];
    }

    /**
     * All payments across all companies.
     */
    public static function payments(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Payment::with('company:id,name,slug')
            ->orderByDesc('created_at');

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate(min($perPage, 100));
    }

    /**
     * All credit notes across all companies.
     */
    public static function creditNotes(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = CreditNote::with('company:id,name,slug')
            ->orderByDesc('created_at');

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate(min($perPage, 100));
    }

    /**
     * Wallet summaries — all companies with a wallet.
     */
    public static function wallets(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = CompanyWallet::with('company:id,name,slug')
            ->orderByDesc('cached_balance');

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        return $query->paginate(min($perPage, 100));
    }

    /**
     * All subscriptions with filters.
     */
    public static function subscriptions(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Subscription::with('company:id,name,slug')
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['plan_key'])) {
            $query->where('plan_key', $filters['plan_key']);
        }

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        return $query->paginate(min($perPage, 100));
    }

    /**
     * Dunning overview — invoices in dunning pipeline.
     */
    public static function dunningInvoices(int $perPage = 20): LengthAwarePaginator
    {
        return Invoice::with('company:id,name,slug')
            ->whereIn('status', ['overdue', 'uncollectible'])
            ->orderByDesc('next_retry_at')
            ->paginate(min($perPage, 100));
    }
}
