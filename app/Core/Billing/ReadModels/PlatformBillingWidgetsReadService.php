<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\LedgerEntry;
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
}
