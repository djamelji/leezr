<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Billing\CompanyWallet;
use App\Core\Billing\CompanyWalletTransaction;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\PaymentOrchestrator;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only queries for company billing.
 * All methods are company-scoped — no cross-company data leakage.
 */
class CompanyBillingReadService
{
    /**
     * Billing overview: subscription + wallet + outstanding summary.
     */
    public static function overview(Company $company): array
    {
        $subscription = static::currentSubscription($company);
        $walletBalance = WalletLedger::balance($company);

        $outstandingCount = Invoice::where('company_id', $company->id)
            ->whereIn('status', ['open', 'overdue'])
            ->count();

        $outstandingAmount = (int) Invoice::where('company_id', $company->id)
            ->whereIn('status', ['open', 'overdue'])
            ->sum('amount_due');

        return [
            'subscription' => $subscription,
            'wallet_balance' => $walletBalance,
            'outstanding_invoices' => $outstandingCount,
            'outstanding_amount' => $outstandingAmount,
            'currency' => 'EUR',
        ];
    }

    /**
     * Paginated invoice list with optional filters.
     *
     * Filters: status, from (date), to (date)
     */
    public static function invoices(Company $company, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::where('company_id', $company->id)
            ->whereNotNull('finalized_at')
            ->orderByDesc('issued_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['from'])) {
            $query->where('issued_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->where('issued_at', '<=', $filters['to']);
        }

        return $query->paginate(min($perPage, 50));
    }

    /**
     * Single invoice detail with lines and credit notes.
     * Returns null if not found or not owned by company.
     */
    public static function invoiceDetail(Company $company, int $invoiceId): ?array
    {
        $invoice = Invoice::with(['lines', 'creditNotes'])
            ->where('company_id', $company->id)
            ->where('id', $invoiceId)
            ->first();

        if (!$invoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
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
                ->where('company_id', $company->id)
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
            'ledger_entries' => LedgerEntry::where('company_id', $company->id)
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
     * Payment history for the company.
     */
    public static function payments(Company $company, int $perPage = 15): LengthAwarePaginator
    {
        return Payment::where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->paginate(min($perPage, 50));
    }

    /**
     * Wallet balance + recent transactions.
     */
    public static function wallet(Company $company, int $recentLimit = 20): array
    {
        $wallet = CompanyWallet::where('company_id', $company->id)->first();

        if (!$wallet) {
            return [
                'balance' => 0,
                'currency' => 'EUR',
                'transactions' => [],
            ];
        }

        $transactions = CompanyWalletTransaction::where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amount,
                'balance_after' => $tx->balance_after,
                'source_type' => $tx->source_type,
                'description' => $tx->description,
                'created_at' => $tx->created_at->toISOString(),
            ])
            ->toArray();

        return [
            'balance' => $wallet->cached_balance,
            'currency' => $wallet->currency ?? 'EUR',
            'transactions' => $transactions,
        ];
    }

    /**
     * Current active/trialing/past_due subscription for the company.
     */
    public static function currentSubscription(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan_key' => $subscription->plan_key,
            'interval' => $subscription->interval,
            'status' => $subscription->status,
            'provider' => $subscription->provider,
            'current_period_start' => $subscription->current_period_start?->toISOString(),
            'current_period_end' => $subscription->current_period_end?->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
            'created_at' => $subscription->created_at->toISOString(),
        ];
    }

    /**
     * Available payment methods for this company's context.
     */
    public static function availablePaymentMethods(Company $company): array
    {
        return PaymentOrchestrator::resolveMethodsForContext(
            marketKey: $company->market_key,
            planKey: CompanyEntitlements::planKey($company),
        );
    }

    /**
     * Billing portal URL (null for internal provider).
     */
    public static function portalUrl(Company $company): ?string
    {
        return null;
    }
}
