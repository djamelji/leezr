<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Subscription;
use App\Core\Plans\Plan;
use Illuminate\Http\JsonResponse;

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

        return response()->json([
            'mrr' => $mrr,
            'arr' => $arr,
            'active_subscriptions' => $activeSubscriptions->count(),
            'trialing_subscriptions' => $trialingSubscriptions,
            'addon_mrr' => $addonMrr,
            'churn_rate' => $churnRate,
        ]);
    }
}
