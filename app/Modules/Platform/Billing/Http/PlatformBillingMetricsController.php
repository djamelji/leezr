<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Subscription;
use App\Core\Plans\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PlatformBillingMetricsController
{
    public function __invoke(): JsonResponse
    {
        $activeSubscriptions = Subscription::whereIn('status', ['active', 'past_due'])
            ->where('is_current', 1)
            ->get();

        $trialingSubscriptions = Subscription::where('status', 'trialing')
            ->where('is_current', 1)
            ->count();

        // MRR: sum of monthly-equivalent price for all active subscriptions
        $plans = Plan::all()->keyBy('key');
        $mrr = 0;

        foreach ($activeSubscriptions as $sub) {
            $plan = $plans[$sub->plan_key] ?? null;

            if (! $plan) {
                continue;
            }

            if ($sub->interval === 'yearly') {
                // Yearly subscribers contribute price_yearly / 12 to MRR
                $mrr += (int) round(($plan->price_yearly ?? 0) / 12);
            } else {
                $mrr += $plan->price_monthly ?? 0;
            }
        }

        // Addon MRR: sum of active addon subscriptions (monthly amounts)
        $addonMrr = (int) CompanyAddonSubscription::active()->sum('amount_cents');

        // ARR
        $arr = ($mrr + $addonMrr) * 12;

        // Churn: subscriptions cancelled in last 30 days / total active
        $cancelledLast30 = Subscription::where('status', 'cancelled')
            ->where('updated_at', '>=', now()->subDays(30))
            ->count();

        $totalActive = $activeSubscriptions->count() + $trialingSubscriptions;
        $churnRate = $totalActive > 0 ? round($cancelledLast30 / $totalActive, 4) : 0;

        // MRR history: monthly MRR for the last 12 months (ADR-315)
        $mrrHistory = $this->mrrHistory($plans);

        // Trial conversion rate (ADR-315)
        $trialConversion = $this->trialConversionRate();

        return response()->json([
            'mrr' => $mrr,
            'arr' => $arr,
            'active_subscriptions' => $activeSubscriptions->count(),
            'trialing_subscriptions' => $trialingSubscriptions,
            'addon_mrr' => $addonMrr,
            'churn_rate' => $churnRate,
            'mrr_history' => $mrrHistory,
            'trial_conversion_rate' => $trialConversion,
        ]);
    }

    /**
     * MRR history: for each of the last 12 months, count active subscriptions
     * that were active at month-end and sum their monthly-equivalent price.
     */
    private function mrrHistory(mixed $plans): array
    {
        $labels = [];
        $series = [];

        for ($i = 11; $i >= 0; $i--) {
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $monthLabel = $monthEnd->format('Y-m');
            $labels[] = $monthLabel;

            // Subscriptions that were active at this month-end
            $subs = Subscription::where('is_current', 1)
                ->whereIn('status', ['active', 'past_due'])
                ->where('created_at', '<=', $monthEnd)
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('cancelled_at')
                        ->orWhere('cancelled_at', '>', $monthEnd);
                })
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

    /**
     * Trial conversion rate: % of trialing subscriptions that converted to active
     * in the last 90 days.
     */
    private function trialConversionRate(): float
    {
        $since = now()->subDays(90);

        // Subscriptions that had a trial and were created in the last 90 days
        $totalTrials = Subscription::whereNotNull('trial_ends_at')
            ->where('created_at', '>=', $since)
            ->count();

        if ($totalTrials === 0) {
            return 0;
        }

        // Of those, how many are now active (converted)
        $converted = Subscription::whereNotNull('trial_ends_at')
            ->where('created_at', '>=', $since)
            ->whereIn('status', ['active', 'past_due'])
            ->count();

        return round($converted / $totalTrials, 4);
    }
}
