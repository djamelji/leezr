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

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'mrr_previous_month' => $mrrPreviousMonth,
            'active_subscriptions' => $activeSubscriptions->count(),
            'trialing_subscriptions' => $trialingSubscriptions,
            'addon_mrr' => $addonMrr,
            'churn_rate' => $churnRate,
            'mrr_history' => $mrrHistory,
            'trial_conversion_rate' => $this->trialConversionRate(),
            'currency' => 'EUR',
        ];
    }

    public function computeMrr(Collection $activeSubscriptions, Collection $plans): int
    {
        $mrr = 0;

        foreach ($activeSubscriptions as $sub) {
            $plan = $plans[$sub->plan_key] ?? null;

            if (! $plan) {
                continue;
            }

            if ($sub->interval === 'yearly') {
                $mrr += (int) round(($plan->price_yearly ?? 0) / 12);
            } else {
                $mrr += $plan->price_monthly ?? 0;
            }
        }

        return $mrr;
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
                ->get(['plan_key', 'interval']);

            $monthMrr = 0;

            foreach ($subs as $sub) {
                $plan = $plans[$sub->plan_key] ?? null;

                if (! $plan) {
                    continue;
                }

                if ($sub->interval === 'yearly') {
                    $monthMrr += (int) round(($plan->price_yearly ?? 0) / 12);
                } else {
                    $monthMrr += $plan->price_monthly ?? 0;
                }
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
