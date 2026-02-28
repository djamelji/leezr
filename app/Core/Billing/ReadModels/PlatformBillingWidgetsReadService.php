<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only queries for billing dashboard widgets.
 *
 * Company-scoped and global methods. Queries LedgerEntry only (immutable, append-only).
 */
class PlatformBillingWidgetsReadService
{
    /**
     * Resolve the single currency for a company's ledger.
     * Returns null if no entries exist.
     *
     * @throws RuntimeException if mixed currencies detected
     */
    public static function currencyForCompany(int $companyId): ?string
    {
        $currencies = LedgerEntry::where('company_id', $companyId)
            ->distinct()
            ->pluck('currency')
            ->filter()
            ->values();

        if ($currencies->isEmpty()) {
            return null;
        }

        if ($currencies->count() > 1) {
            throw new RuntimeException("Mixed currencies for company {$companyId}: " . $currencies->implode(', '));
        }

        return $currencies->first();
    }

    /**
     * Revenue trend — daily aggregation of REVENUE credits.
     *
     * @return array{labels: string[], series: float[]}
     */
    public static function revenueTrend(int $companyId, Carbon $from, Carbon $to, string $bucket = 'day'): array
    {
        $rows = LedgerEntry::where('company_id', $companyId)
            ->where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to])
            ->select(
                DB::raw('DATE(recorded_at) as date_label'),
                DB::raw('SUM(credit) as total')
            )
            ->groupBy('date_label')
            ->orderBy('date_label')
            ->get();

        // Build continuous date range
        $labels = [];
        $seriesMap = [];

        foreach ($rows as $row) {
            $seriesMap[$row->date_label] = (float) $row->total;
        }

        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $series = [];

        while ($current->lte($end)) {
            $key = $current->toDateString();
            $labels[] = $key;
            $series[] = $seriesMap[$key] ?? 0.0;
            $current->addDay();
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * Sum of refunds (REFUND debit) in period.
     *
     * @return array{refunds: float}
     */
    public static function refundTotals(int $companyId, Carbon $from, Carbon $to): array
    {
        $total = (float) LedgerEntry::where('company_id', $companyId)
            ->where('entry_type', 'refund_issued')
            ->where('account_code', 'REFUND')
            ->whereBetween('recorded_at', [$from, $to])
            ->sum('debit');

        return ['refunds' => $total];
    }

    /**
     * Sum of revenue (REVENUE credit) in period.
     *
     * @return array{revenue: float}
     */
    public static function revenueTotals(int $companyId, Carbon $from, Carbon $to): array
    {
        $total = (float) LedgerEntry::where('company_id', $companyId)
            ->where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to])
            ->sum('credit');

        return ['revenue' => $total];
    }

    /**
     * Net AR outstanding = SUM(debit) - SUM(credit) for account_code=AR.
     *
     * @return array{outstanding: float}
     */
    public static function arOutstanding(int $companyId): array
    {
        $result = LedgerEntry::where('company_id', $companyId)
            ->where('account_code', 'AR')
            ->select(
                DB::raw('COALESCE(SUM(debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(credit), 0) as total_credit')
            )
            ->first();

        $outstanding = (float) $result->total_debit - (float) $result->total_credit;

        return ['outstanding' => $outstanding];
    }

    // ── Global (cross-company) methods ──────────────────────────────

    /**
     * Resolve the currency across all companies.
     * Returns single currency, 'MULTI' if mixed, or null if no entries.
     */
    public static function currencyGlobal(): ?string
    {
        $currencies = LedgerEntry::distinct()
            ->pluck('currency')
            ->filter()
            ->values();

        if ($currencies->isEmpty()) {
            return null;
        }

        return $currencies->count() === 1 ? $currencies->first() : 'MULTI';
    }

    /**
     * Global revenue trend — daily aggregation across all companies.
     *
     * @return array{labels: string[], series: float[]}
     */
    public static function revenueTrendGlobal(Carbon $from, Carbon $to): array
    {
        $rows = LedgerEntry::where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to])
            ->select(
                DB::raw('DATE(recorded_at) as date_label'),
                DB::raw('SUM(credit) as total')
            )
            ->groupBy('date_label')
            ->orderBy('date_label')
            ->get();

        $seriesMap = [];

        foreach ($rows as $row) {
            $seriesMap[$row->date_label] = (float) $row->total;
        }

        $labels = [];
        $series = [];
        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            $key = $current->toDateString();
            $labels[] = $key;
            $series[] = $seriesMap[$key] ?? 0.0;
            $current->addDay();
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * Global refund totals across all companies.
     *
     * @return array{refunds: float}
     */
    public static function refundTotalsGlobal(Carbon $from, Carbon $to): array
    {
        $total = (float) LedgerEntry::where('entry_type', 'refund_issued')
            ->where('account_code', 'REFUND')
            ->whereBetween('recorded_at', [$from, $to])
            ->sum('debit');

        return ['refunds' => $total];
    }

    /**
     * Global revenue totals across all companies.
     *
     * @return array{revenue: float}
     */
    public static function revenueTotalsGlobal(Carbon $from, Carbon $to): array
    {
        $total = (float) LedgerEntry::where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to])
            ->sum('credit');

        return ['revenue' => $total];
    }

    /**
     * Global AR outstanding across all companies.
     *
     * @return array{outstanding: float}
     */
    public static function arOutstandingGlobal(): array
    {
        $result = LedgerEntry::where('account_code', 'AR')
            ->select(
                DB::raw('COALESCE(SUM(debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(credit), 0) as total_credit')
            )
            ->first();

        $outstanding = (float) $result->total_debit - (float) $result->total_credit;

        return ['outstanding' => $outstanding];
    }

    // ── Dataset methods (ADR-156) ────────────────────────────────

    /**
     * Activity dataset — last payments, invoices, and credit notes.
     */
    public static function activityDataset(string $scope, ?int $companyId, Carbon $from, Carbon $to): array
    {
        $paymentQuery = Payment::with('company:id,name')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(5);

        $invoiceQuery = Invoice::with('company:id,name')
            ->whereBetween('issued_at', [$from, $to])
            ->orderByDesc('issued_at')
            ->limit(5);

        $creditNoteQuery = CreditNote::with('company:id,name')
            ->whereBetween('issued_at', [$from, $to])
            ->orderByDesc('issued_at')
            ->limit(5);

        if ($scope === 'company' && $companyId) {
            $paymentQuery->where('company_id', $companyId);
            $invoiceQuery->where('company_id', $companyId);
            $creditNoteQuery->where('company_id', $companyId);
        }

        return [
            'last_payments' => $paymentQuery->get()->map(fn ($p) => [
                'id' => $p->id,
                'company_name' => $p->company?->name,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'date' => $p->created_at?->toIso8601String(),
            ])->all(),
            'last_invoices' => $invoiceQuery->get()->map(fn ($i) => [
                'id' => $i->id,
                'company_name' => $i->company?->name,
                'number' => $i->number,
                'amount' => $i->amount,
                'currency' => $i->currency,
                'status' => $i->status,
                'date' => $i->issued_at?->toIso8601String(),
            ])->all(),
            'last_refunds' => $creditNoteQuery->get()->map(fn ($cn) => [
                'id' => $cn->id,
                'company_name' => $cn->company?->name,
                'amount' => $cn->amount,
                'currency' => $cn->currency,
                'reason' => $cn->reason,
                'status' => $cn->status,
                'date' => $cn->issued_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * KPIs dataset — revenue, refunds, AR outstanding.
     */
    public static function kpisDataset(string $scope, ?int $companyId, Carbon $from, Carbon $to): array
    {
        $revenueQuery = LedgerEntry::where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to]);

        $refundQuery = LedgerEntry::where('entry_type', 'refund_issued')
            ->where('account_code', 'REFUND')
            ->whereBetween('recorded_at', [$from, $to]);

        $arQuery = LedgerEntry::where('account_code', 'AR');

        if ($scope === 'company' && $companyId) {
            $revenueQuery->where('company_id', $companyId);
            $refundQuery->where('company_id', $companyId);
            $arQuery->where('company_id', $companyId);
            $currency = static::currencyForCompany($companyId);
        } else {
            $currency = static::currencyGlobal();
        }

        $revenue = (float) $revenueQuery->sum('credit');
        $refunds = (float) $refundQuery->sum('debit');

        $arResult = $arQuery->select(
            DB::raw('COALESCE(SUM(debit), 0) as total_debit'),
            DB::raw('COALESCE(SUM(credit), 0) as total_credit')
        )->first();

        $outstanding = (float) $arResult->total_debit - (float) $arResult->total_credit;

        return [
            'revenue' => $revenue,
            'refunds' => $refunds,
            'outstanding' => $outstanding,
            'currency' => $currency,
            'mrr' => null,
        ];
    }

    /**
     * Risk dataset — failed payments, overdue invoices, failure reasons.
     */
    public static function riskDataset(string $scope, ?int $companyId): array
    {
        $failedQuery = Payment::where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7));

        $overdueQuery = Invoice::where('status', 'open')
            ->where('due_at', '<', now());

        if ($scope === 'company' && $companyId) {
            $failedQuery->where('company_id', $companyId);
            $overdueQuery->where('company_id', $companyId);
        }

        $failedCount = $failedQuery->count();
        $overdueCount = $overdueQuery->count();

        // Top failure reasons from payment metadata
        $reasonQuery = Payment::where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7));

        if ($scope === 'company' && $companyId) {
            $reasonQuery->where('company_id', $companyId);
        }

        $reasons = $reasonQuery->get()
            ->groupBy(fn ($p) => $p->metadata['failure_reason'] ?? 'unknown')
            ->map(fn ($group, $reason) => ['reason' => $reason, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->take(5)
            ->all();

        return [
            'failed_payments_7d' => $failedCount,
            'pending_dunning' => $overdueCount,
            'top_failure_reasons' => $reasons,
        ];
    }

    /**
     * Timeseries dataset — revenue trend + cashflow trend.
     */
    public static function timeseriesDataset(string $scope, ?int $companyId, Carbon $from, Carbon $to): array
    {
        $revenueQuery = LedgerEntry::where('entry_type', 'invoice_issued')
            ->where('account_code', 'REVENUE')
            ->whereBetween('recorded_at', [$from, $to])
            ->select(
                DB::raw('DATE(recorded_at) as date_label'),
                DB::raw('SUM(credit) as total')
            )
            ->groupBy('date_label')
            ->orderBy('date_label');

        $cashflowQuery = LedgerEntry::where('entry_type', 'payment_received')
            ->where('account_code', 'CASH')
            ->whereBetween('recorded_at', [$from, $to])
            ->select(
                DB::raw('DATE(recorded_at) as date_label'),
                DB::raw('SUM(debit) as total')
            )
            ->groupBy('date_label')
            ->orderBy('date_label');

        if ($scope === 'company' && $companyId) {
            $revenueQuery->where('company_id', $companyId);
            $cashflowQuery->where('company_id', $companyId);
            $currency = static::currencyForCompany($companyId);
        } else {
            $currency = static::currencyGlobal();
        }

        $revenueRows = $revenueQuery->get();
        $cashflowRows = $cashflowQuery->get();

        return [
            'revenue_trend' => static::buildContinuousSeries($revenueRows, $from, $to),
            'cashflow_trend' => static::buildContinuousSeries($cashflowRows, $from, $to),
            'currency' => $currency,
        ];
    }

    /**
     * Build continuous date series filling gaps with 0.
     */
    public static function buildContinuousSeries($rows, Carbon $from, Carbon $to): array
    {
        $seriesMap = [];

        foreach ($rows as $row) {
            $seriesMap[$row->date_label] = (float) $row->total;
        }

        $labels = [];
        $series = [];
        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            $key = $current->toDateString();
            $labels[] = $key;
            $series[] = $seriesMap[$key] ?? 0.0;
            $current->addDay();
        }

        return ['labels' => $labels, 'series' => $series];
    }
}
