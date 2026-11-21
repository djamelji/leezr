<?php

namespace App\Core\Billing;

use App\Core\Plans\Plan;
use App\Modules\Core\Billing\Services\CouponService;
use Illuminate\Support\Collection;

/**
 * ADR-362: Billing metrics calculation — extracted from PlatformBillingMetricsController.
 *
 * Pure calculation service with no HTTP dependency.
 */
class BillingMetricsCalculationService
{
    /**
     * ADR-841 (Leezr 4.2 M4) — THE recurring revenue definition, shared by every Platform screen
     * (dashboard, billing, companies list, company record, exports).
     *
     * MRR = Σ over CURRENT subscriptions whose status is billed (active, past_due) of the monthly-normalised
     * amount the renewal invoice bills, HT : contracted plan price (ADR-587 snapshot) + active add-ons − the
     * recurring coupon discount (CouponService, same rule as PricingEngine / billing:renew). Yearly amounts / 12.
     * Excluded : trials, pending, suspended, cancelled, companies without a subscription, scheduled plan changes
     * (not effective until executed), one-off prorations, wallet movements, taxes. Per currency (no FX).
     * ARR = MRR × 12. Invoiced, collected, refunded and outstanding amounts come from the ledger, not from here.
     */
    public const RECURRING_STATUSES = ['active', 'past_due'];

    /** @return array<string, mixed> */
    public function recurringPortfolio(): array
    {
        try {
            $subs = Subscription::where('is_current', 1)->whereIn('status', self::RECURRING_STATUSES)
                ->with(['company.market', 'coupon'])->get();
            $plans = Plan::all()->keyBy('key');
            $addons = CompanyAddonSubscription::active()->whereIn('company_id', $subs->pluck('company_id')->unique())->get()->groupBy('company_id');
            $trialing = Subscription::where('is_current', 1)->where('status', 'trialing')->count();
        } catch (\Throwable) {
            $subs = collect();
            $plans = collect();
            $addons = collect();
            $trialing = 0;
        }

        $out = ['mrr' => 0, 'mrr_plans' => 0, 'mrr_addons' => 0, 'mrr_discounts' => 0, 'past_due_mrr' => 0,
            'by_currency' => [], 'companies' => [], 'active_subscriptions' => 0, 'past_due_subscriptions' => 0,
            'trialing_subscriptions' => $trialing, 'currency' => config('billing.reporting_currency', 'EUR'),
            'computed_at' => now()->toIso8601String()];

        foreach ($subs as $sub) {
            $r = $this->recurringForSubscription($sub, $plans, $addons->get($sub->company_id, collect()));
            $out['mrr'] += $r['monthly'];
            $out['mrr_plans'] += $r['plan'];
            $out['mrr_addons'] += $r['addons'];
            $out['mrr_discounts'] += $r['discount'];
            $out['by_currency'][$r['currency']] = ($out['by_currency'][$r['currency']] ?? 0) + $r['monthly'];
            $out['companies'][$sub->company_id] = $r + ['status' => $sub->status, 'interval' => $sub->interval];
            if ($sub->status === 'past_due') {
                $out['past_due_mrr'] += $r['monthly'];
                $out['past_due_subscriptions']++;
            } else {
                $out['active_subscriptions']++;
            }
        }
        $out['arr'] = $out['mrr'] * 12;
        $out['multi_currency'] = count($out['by_currency']) > 1;

        return $out;
    }

    /**
     * Monthly-normalised recurring amount (cents, HT) of ONE subscription, as the renewal bills it.
     *
     * @return array{plan:int, addons:int, discount:int, monthly:int, currency:string}
     */
    public function recurringForSubscription(Subscription $sub, Collection $plans, Collection $addons): array
    {
        $months = $sub->interval === 'yearly' ? 12 : 1;
        $plan = $this->subscriptionMonthlyAmount($sub, $plans);
        $addonMonthly = (int) $addons->sum(fn ($a) => $a->interval === 'yearly' ? (int) round($a->amount_cents / 12) : (int) $a->amount_cents);

        $discount = 0;
        $coupon = $sub->relationLoaded('coupon') ? $sub->coupon : ($sub->coupon_id ? BillingCoupon::find($sub->coupon_id) : null);
        if ($coupon && $coupon->isUsable() && ($sub->coupon_months_remaining === null || $sub->coupon_months_remaining > 0)) {
            // same rule as PricingEngine::forCurrentPeriod : the coupon applies to the period subtotal
            $periodDiscount = app(CouponService::class)->calculateDiscount($coupon, ($plan + $addonMonthly) * $months);
            $discount = (int) round($periodDiscount / $months);
        }

        $currency = strtoupper($sub->company?->market?->currency ?? config('billing.reporting_currency', 'EUR'));

        return ['plan' => $plan, 'addons' => $addonMonthly, 'discount' => $discount,
            'monthly' => max(0, $plan + $addonMonthly - $discount), 'currency' => $currency];
    }

    public function calculate(): array
    {
        $portfolio = $this->recurringPortfolio();

        try {
            $activeSubscriptions = Subscription::whereIn('status', self::RECURRING_STATUSES)
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

        // ADR-841 : one definition — plan + add-ons of BILLED subscriptions − recurring coupons ; ARR = MRR × 12.
        // (Before : MRR = plans only, add-ons of every company — trials and companies without subscription
        // included — added to the ARR only, so ARR ≠ 12 × MRR.)
        $mrr = $portfolio['mrr'];
        $mrrByCurrency = $portfolio['by_currency'];
        $addonMrr = $portfolio['mrr_addons'];
        $arr = $portfolio['arr'];

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
            'mrr_plans' => $portfolio['mrr_plans'],
            'mrr_discounts' => $portfolio['mrr_discounts'],
            'past_due_mrr' => $portfolio['past_due_mrr'],
            'past_due_subscriptions' => $portfolio['past_due_subscriptions'],
            'multi_currency' => $portfolio['multi_currency'],
            'computed_at' => $portfolio['computed_at'],
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

    public function subscriptionMonthlyAmount($sub, Collection $plans): int
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
                ->whereIn('status', self::RECURRING_STATUSES)
                ->where('created_at', '<=', $monthEnd)
                ->with('coupon')
                ->get();
            $addons = CompanyAddonSubscription::active()->whereIn('company_id', $subs->pluck('company_id')->unique())->get()->groupBy('company_id');

            $monthMrr = 0;

            foreach ($subs as $sub) {
                // ADR-841 : same definition as the current MRR (reconstructed from today's subscriptions — ADR-747 bias)
                $monthMrr += $this->recurringForSubscription($sub, $plans, $addons->get($sub->company_id, collect()))['monthly'];
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
