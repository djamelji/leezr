<?php

namespace App\Core\Billing;

use App\Core\Plans\Plan;
use Illuminate\Support\Collection;

/**
 * ADR-362: Billing metrics calculation — extracted from PlatformBillingMetricsController.
 *
 * Pure calculation service with no HTTP dependency.
 */
class BillingMetricsCalculationService
{
    public function calculate(): array
    {
        try {
            $activeSubscriptions = Subscription::whereIn('status', ['active', 'past_due'])
                ->where('is_current', 1)
                ->get();

            $trialingSubscriptions = Subscription::where('status', 'trialing')
                ->where('is_current', 1)
                ->count();
        } catch (\Throwable) {
            $activeSubscriptions = collect();
            $trialingSubscriptions = 0;
        }

        try {
            $plans = Plan::all()->keyBy('key');
        } catch (\Throwable) {
            $plans = collect();
        }

        $mrr = $this->computeMrr($activeSubscriptions, $plans);
        $mrrByCurrency = $this->computeMrrByCurrency($activeSubscriptions, $plans);

        try {
            $addonMrr = (int) CompanyAddonSubscription::active()->sum('amount_cents');
        } catch (\Throwable) {
            $addonMrr = 0;
        }

        $arr = ($mrr + $addonMrr) * 12;

        try {
            $cancelledLast30 = Subscription::where('status', 'cancelled')
                ->where('updated_at', '>=', now()->subDays(30))
                ->count();
        } catch (\Throwable) {
            $cancelledLast30 = 0;
        }

        $totalActive = $activeSubscriptions->count() + $trialingSubscriptions;
        $churnRate = $totalActive > 0 ? round($cancelledLast30 / $totalActive, 4) : 0;

        $mrrHistory = $this->mrrHistory($plans);
        $mrrPreviousMonth = $mrrHistory['series'][count($mrrHistory['series']) - 2] ?? 0;

        // ADR-631 (LOT 7A): renewals BLOCKED for tariff review (ADR-593) — unreliable contracted
        // price (null/provisional/flagged) on a current subscription, deferred (never billed).
        $blockedRenewals = Subscription::whereIn('status', ['active', 'trialing', 'past_due'])
            ->where('is_current', 1)
            ->where(fn ($q) => $q->whereNull('plan_price_source')
                ->orWhere('plan_price_source', 'provisional')
                ->orWhereJsonContains('metadata->pricing_review_required', true))
            ->count();

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'blocked_renewals' => $blockedRenewals,
            'mrr_previous_month' => $mrrPreviousMonth,
            'active_subscriptions' => $activeSubscriptions->count(),
            'trialing_subscriptions' => $trialingSubscriptions,
            'addon_mrr' => $addonMrr,
            'churn_rate' => $churnRate,
            'mrr_history' => $mrrHistory,
            'trial_conversion_rate' => $this->trialConversionRate(),
            'currency' => config('billing.reporting_currency', 'EUR'),
            'mrr_by_currency' => $mrrByCurrency,
        ];
    }

    public function computeMrr(Collection $activeSubscriptions, Collection $plans): int
    {
        $mrr = 0;

        foreach ($activeSubscriptions as $sub) {
            $mrr += $this->subscriptionMonthlyAmount($sub, $plans);
        }

        return $mrr;
    }

    /**
     * F10: per-currency MRR breakdown. Plan prices carry no FX conversion, so a single
     * scalar MRR silently mixes currencies in a multi-market portfolio. Grouping by the
     * subscription's billing currency (company market/wallet currency) keeps each figure
     * exact. Returns ['EUR' => cents, 'GBP' => cents, …].
     *
     * @return array<string, int>
     */
    public function computeMrrByCurrency(Collection $activeSubscriptions, Collection $plans): array
    {
        $byCurrency = [];

        foreach ($activeSubscriptions as $sub) {
            $amount = $this->subscriptionMonthlyAmount($sub, $plans);

            if ($amount === 0) {
                continue;
            }

            $currency = strtoupper(
                $sub->company?->market?->currency
                    ?? config('billing.reporting_currency', 'EUR')
            );

            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $amount;
        }

        return $byCurrency;
    }

    private function subscriptionMonthlyAmount($sub, Collection $plans): int
    {
        $plan = $plans[$sub->plan_key] ?? null;

        // ADR-591: MRR must reflect the CONTRACTED price (snapshot, ADR-587), not the live public
        // price — changing a plan's public price must NOT move the MRR of existing subscribers.
        $contracted = $sub->plan_price_cents;

        if ($sub->interval === 'yearly') {
            $yearly = $contracted ?? ($plan->price_yearly ?? 0);

            return (int) round(($yearly ?? 0) / 12);
        }

        return (int) ($contracted ?? ($plan->price_monthly ?? 0));
    }

    public function mrrHistory(Collection $plans): array
    {
        $labels = [];
        $series = [];

        for ($i = 11; $i >= 0; $i--) {
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $labels[] = $monthEnd->format('Y-m');

            $subs = Subscription::where('is_current', 1)
                ->whereIn('status', ['active', 'past_due'])
                ->where('created_at', '<=', $monthEnd)
                ->get(['plan_key', 'interval', 'plan_price_cents']);

            $monthMrr = 0;

            foreach ($subs as $sub) {
                // ADR-591: use the contracted snapshot (fallback to catalog for legacy rows).
                $monthMrr += $this->subscriptionMonthlyAmount($sub, $plans);
            }

            $series[] = $monthMrr;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    public function trialConversionRate(): float
    {
        $since = now()->subDays(90);

        $totalTrials = Subscription::whereNotNull('trial_ends_at')
            ->where('created_at', '>=', $since)
            ->count();

        if ($totalTrials === 0) {
            return 0;
        }

        $converted = Subscription::whereNotNull('trial_ends_at')
            ->where('created_at', '>=', $since)
            ->whereIn('status', ['active', 'past_due'])
            ->count();

        return round($converted / $totalTrials, 4);
    }
}
