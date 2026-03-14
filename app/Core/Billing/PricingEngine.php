<?php

namespace App\Core\Billing;

use App\Core\Billing\DTOs\CouponInfo;
use App\Core\Billing\DTOs\PlanChangeBreakdown;
use App\Core\Billing\DTOs\PriceBreakdown;
use App\Core\Billing\DTOs\PriceLine;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\Plan;
use App\Modules\Core\Billing\Services\CouponService;
use App\Modules\Core\Billing\Services\TaxContextResolver;
use App\Core\Billing\InvoiceLineDescriptor;
use Carbon\CarbonImmutable;

/**
 * Centralized pricing engine — single source of truth for all price calculations.
 *
 * Produces PriceBreakdown objects consumed by:
 *   - Preview endpoints → toArray() → JSON
 *   - InvoiceIssuer → toInvoiceLines() → addLine()
 *   - PlanChangeExecutor → proration snapshot
 *
 * ADR-324: All pricing flows must converge here.
 */
class PricingEngine
{
    /**
     * PriceBreakdown for the current subscription period.
     *
     * Consumers: nextInvoicePreview, CheckoutSessionActivator, BillingRenewCommand (P2).
     */
    public static function forCurrentPeriod(Subscription $subscription, Company $company, string $locale = 'fr-FR'): PriceBreakdown
    {
        $desc = InvoiceLineDescriptor::resolve($locale);
        $plan = Plan::where('key', $subscription->plan_key)->first();
        $planPriceCents = static::catalogPriceCents($plan, $subscription->interval);

        $lines = [];

        // Plan line
        if ($planPriceCents > 0) {
            $lines[] = new PriceLine(
                type: 'plan',
                description: $desc->plan($plan->name ?? $subscription->plan_key),
                unitAmount: $planPriceCents,
            );
        }

        // Active addon lines
        $addonSubs = CompanyAddonSubscription::where('company_id', $company->id)
            ->active()->get();

        $addonModuleKeys = $addonSubs->pluck('module_key')->toArray();
        $addonModuleNames = ! empty($addonModuleKeys)
            ? PlatformModule::whereIn('key', $addonModuleKeys)->pluck('name', 'key')->toArray()
            : [];

        foreach ($addonSubs as $addon) {
            $lines[] = new PriceLine(
                type: 'addon',
                description: $desc->addon($addonModuleNames[$addon->module_key] ?? $addon->module_key),
                unitAmount: $addon->amount_cents,
                moduleKey: $addon->module_key,
            );
        }

        // Coupon discount
        $couponInfo = null;
        if ($subscription->coupon_id) {
            $coupon = $subscription->relationLoaded('coupon')
                ? $subscription->coupon
                : BillingCoupon::find($subscription->coupon_id);

            if ($coupon && $coupon->isUsable()) {
                $positiveTotal = array_sum(array_map(fn (PriceLine $l) => max(0, $l->amount), $lines));
                $discount = app(CouponService::class)->calculateDiscount($coupon, $positiveTotal);

                if ($discount > 0) {
                    $lines[] = new PriceLine(
                        type: 'discount',
                        description: $desc->coupon($coupon->code),
                        unitAmount: -$discount,
                        metadata: ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code],
                    );

                    $couponInfo = new CouponInfo(
                        id: $coupon->id,
                        code: $coupon->code,
                        type: $coupon->type,
                        value: $coupon->value,
                        discount: $discount,
                        monthsRemaining: $subscription->coupon_months_remaining,
                    );
                }
            }
        }

        // Tax
        $taxContext = TaxContextResolver::resolve($company);
        $currency = WalletLedger::ensureWallet($company)->currency;

        return new PriceBreakdown(
            lines: $lines,
            taxRateBps: $taxContext->taxRateBps,
            taxExemptionReason: $taxContext->exemptionReason,
            currency: $currency,
            coupon: $couponInfo,
        );
    }

    /**
     * PlanChangeBreakdown: proration (immediate) + next recurring period.
     *
     * Consumers: planChangePreview, PlanChangeExecutor::schedule().
     */
    public static function forPlanChange(
        Company $company,
        Subscription $subscription,
        string $toPlanKey,
        string $toInterval = 'monthly',
        string $locale = 'fr-FR',
    ): PlanChangeBreakdown {
        $desc = InvoiceLineDescriptor::resolve($locale);
        $plans = Plan::whereIn('key', [$subscription->plan_key, $toPlanKey])->get()->keyBy('key');
        $fromPlan = $plans[$subscription->plan_key] ?? null;
        $toPlan = $plans[$toPlanKey] ?? null;

        if (! $fromPlan || ! $toPlan) {
            throw new \RuntimeException("Plan not found: {$subscription->plan_key} or {$toPlanKey}");
        }

        $fromInterval = $subscription->interval ?? 'monthly';
        $fromLevel = $fromPlan->level;
        $toLevel = $toPlan->level;
        $isUpgrade = $toLevel > $fromLevel;
        $isIntervalChange = $subscription->plan_key === $toPlanKey && $fromInterval !== $toInterval;

        $policy = PlatformBillingPolicy::instance();
        $timing = $isIntervalChange
            ? ($policy->interval_change_timing ?? 'immediate')
            : ($isUpgrade ? $policy->upgrade_timing : $policy->downgrade_timing);

        // Prices: effective (with coupon) for old plan credit, catalog for new plan charge
        $oldPriceCatalog = static::catalogPriceCents($fromPlan, $fromInterval);
        $oldPriceEffective = static::effectivePriceCents($subscription, $fromPlan);
        $newPriceCatalog = static::catalogPriceCents($toPlan, $toInterval);

        // Proration
        $proration = null;
        $prorationDetails = null;

        $skipProration = $subscription->status === 'trialing'
            && $policy->trial_plan_change_behavior === 'continue_trial';

        if ($timing === 'immediate'
            && ! $skipProration
            && $subscription->current_period_start
            && $subscription->current_period_end
            && $subscription->current_period_end->gt(now())
        ) {
            $proration = ProrationCalculator::compute(
                oldPriceCents: $oldPriceEffective,
                newPriceCents: $newPriceCatalog,
                periodStart: CarbonImmutable::instance($subscription->current_period_start),
                periodEnd: CarbonImmutable::instance($subscription->current_period_end),
                changeDate: CarbonImmutable::now(),
            );

            $prorationDetails = [
                'credit_old_plan' => $proration['credit'],
                'charge_new_plan' => $proration['charge'],
                'net' => $proration['net'],
                'days_remaining' => $proration['days_remaining'],
                'total_days' => $proration['total_days'],
            ];
        }

        // Tax context
        $taxContext = TaxContextResolver::resolve($company);
        $currency = WalletLedger::ensureWallet($company)->currency;

        // Coupon info
        $couponInfo = null;
        $coupon = null;
        if ($subscription->coupon_id) {
            $coupon = $subscription->relationLoaded('coupon')
                ? $subscription->coupon
                : BillingCoupon::find($subscription->coupon_id);

            if ($coupon && $coupon->isUsable()) {
                $couponInfo = new CouponInfo(
                    id: $coupon->id,
                    code: $coupon->code,
                    type: $coupon->type,
                    value: $coupon->value,
                    discount: 0, // will be computed per-breakdown
                    monthsRemaining: $subscription->coupon_months_remaining,
                );
            }
        }

        // Immediate PriceBreakdown (proration lines)
        $immediateBreakdown = null;
        if ($proration && $proration['net'] !== 0) {
            $immediateLines = [];

            if ($proration['credit'] > 0) {
                $immediateLines[] = new PriceLine(
                    type: 'proration',
                    description: $desc->prorationCredit($fromPlan->name, CarbonImmutable::now(), CarbonImmutable::instance($subscription->current_period_end), $proration['days_remaining']),
                    unitAmount: -$proration['credit'],
                );
            }

            if ($proration['charge'] > 0) {
                $immediateLines[] = new PriceLine(
                    type: 'proration',
                    description: $desc->prorationCharge($toPlan->name, CarbonImmutable::now(), CarbonImmutable::instance($subscription->current_period_end), $proration['days_remaining']),
                    unitAmount: $proration['charge'],
                );
            }

            $immediateBreakdown = new PriceBreakdown(
                lines: $immediateLines,
                taxRateBps: $taxContext->taxRateBps,
                taxExemptionReason: $taxContext->exemptionReason,
                currency: $currency,
            );
        }

        // Next period: new plan + recalculated addons + coupon if transferable
        $nextLines = [];
        $nextLines[] = new PriceLine(
            type: 'plan',
            description: $desc->plan($toPlan->name),
            unitAmount: $newPriceCatalog,
        );

        // Recalculate addons for new plan/interval
        $activeAddons = CompanyAddonSubscription::where('company_id', $company->id)->active()->get();
        $addonModuleKeys = $activeAddons->pluck('module_key')->toArray();
        $addonModuleNames = ! empty($addonModuleKeys)
            ? PlatformModule::whereIn('key', $addonModuleKeys)->pluck('name', 'key')->toArray()
            : [];

        $addonLinesData = [];
        foreach ($activeAddons as $addon) {
            $module = PlatformModule::where('key', $addon->module_key)->first();
            $currentAmount = $addon->amount_cents;
            $newMonthly = $module ? ModuleQuoteCalculator::computeAmount($module, $toPlanKey) : $currentAmount;
            $newAmount = $toInterval === 'yearly' ? $newMonthly * 12 : $newMonthly;

            $nextLines[] = new PriceLine(
                type: 'addon',
                description: $desc->addon($addonModuleNames[$addon->module_key] ?? $addon->module_key),
                unitAmount: $newAmount,
                moduleKey: $addon->module_key,
            );

            $addonLinesData[] = [
                'module_key' => $addon->module_key,
                'name' => $addonModuleNames[$addon->module_key] ?? $addon->module_key,
                'current_amount' => $currentAmount,
                'new_amount' => $newAmount,
                'difference' => $newAmount - $currentAmount,
            ];
        }

        // Coupon on next period
        $nextCouponInfo = null;
        if ($coupon && $coupon->isUsable() && $couponInfo) {
            $positiveTotal = array_sum(array_map(
                fn (PriceLine $l) => max(0, $l->amount), $nextLines
            ));
            $nextDiscount = app(CouponService::class)->calculateDiscount($coupon, $positiveTotal);

            if ($nextDiscount > 0) {
                $nextLines[] = new PriceLine(
                    type: 'discount',
                    description: $desc->coupon($coupon->code),
                    unitAmount: -$nextDiscount,
                    metadata: ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code],
                );

                $nextCouponInfo = new CouponInfo(
                    id: $coupon->id,
                    code: $coupon->code,
                    type: $coupon->type,
                    value: $coupon->value,
                    discount: $nextDiscount,
                    monthsRemaining: $couponInfo->monthsRemaining,
                );
            }
        }

        $nextPeriodBreakdown = new PriceBreakdown(
            lines: $nextLines,
            taxRateBps: $taxContext->taxRateBps,
            taxExemptionReason: $taxContext->exemptionReason,
            currency: $currency,
            coupon: $nextCouponInfo,
        );

        return new PlanChangeBreakdown(
            timing: $timing,
            isUpgrade: $isUpgrade,
            isIntervalChange: $isIntervalChange,
            currency: $currency,
            fromPlan: [
                'key' => $subscription->plan_key,
                'name' => $fromPlan->name,
                'price' => $oldPriceCatalog,
                'effective_price' => $oldPriceEffective,
                'interval' => $fromInterval,
            ],
            toPlan: [
                'key' => $toPlanKey,
                'name' => $toPlan->name,
                'price' => $newPriceCatalog,
                'interval' => $toInterval,
            ],
            immediate: $immediateBreakdown,
            prorationDetails: $prorationDetails,
            nextPeriod: $nextPeriodBreakdown,
            activeCoupon: $couponInfo,
            addonLines: $addonLinesData,
        );
    }

    /**
     * PriceBreakdown for registration tunnel (pre-company).
     *
     * Consumers: POST /api/public/estimate-registration, frontend summary.
     */
    public static function forRegistration(
        string $planKey,
        string $interval,
        string $marketKey,
        ?BillingCoupon $coupon = null,
        array $addonModuleKeys = [],
        string $locale = 'fr-FR',
    ): PriceBreakdown {
        $desc = InvoiceLineDescriptor::resolve($locale);
        $plan = Plan::where('key', $planKey)->first();
        if (! $plan) {
            throw new \RuntimeException("Plan not found: {$planKey}");
        }

        $planPriceCents = static::catalogPriceCents($plan, $interval);

        $lines = [];

        if ($planPriceCents > 0) {
            $lines[] = new PriceLine(
                type: 'plan',
                description: $desc->plan($plan->name),
                unitAmount: $planPriceCents,
            );
        }

        // Addons
        foreach ($addonModuleKeys as $moduleKey) {
            $module = PlatformModule::where('key', $moduleKey)->first();
            if (! $module) {
                continue;
            }

            $amount = ModuleQuoteCalculator::computeAmount($module, $planKey);
            if ($interval === 'yearly') {
                $amount *= 12;
            }

            if ($amount > 0) {
                $lines[] = new PriceLine(
                    type: 'addon',
                    description: $desc->addon($module->display_name_override ?? $moduleKey),
                    unitAmount: $amount,
                    moduleKey: $moduleKey,
                );
            }
        }

        // Coupon
        $couponInfo = null;
        if ($coupon && $coupon->isUsable()) {
            $positiveTotal = array_sum(array_map(fn (PriceLine $l) => max(0, $l->amount), $lines));
            $discount = app(CouponService::class)->calculateDiscount($coupon, $positiveTotal);

            if ($discount > 0) {
                $lines[] = new PriceLine(
                    type: 'discount',
                    description: $desc->coupon($coupon->code),
                    unitAmount: -$discount,
                    metadata: ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code],
                );

                $couponInfo = new CouponInfo(
                    id: $coupon->id,
                    code: $coupon->code,
                    type: $coupon->type,
                    value: $coupon->value,
                    discount: $discount,
                    monthsRemaining: $coupon->duration_months,
                );
            }
        }

        // Tax — resolve from market_key (no company yet)
        $market = Market::where('key', $marketKey)->first();
        $taxRateBps = $market ? ($market->vat_rate_bps ?? 0) : 0;
        $currency = $market ? ($market->currency ?? 'EUR') : 'EUR';

        return new PriceBreakdown(
            lines: $lines,
            taxRateBps: $taxRateBps,
            taxExemptionReason: null,
            currency: $currency,
            coupon: $couponInfo,
        );
    }

    // ── Helpers ──────────────────────────────────────────

    /** Catalog price from Plan DB (cents). */
    public static function catalogPriceCents(?Plan $plan, string $interval): int
    {
        if (! $plan) {
            return 0;
        }

        return $interval === 'yearly' ? ($plan->price_yearly ?? 0) : ($plan->price_monthly ?? 0);
    }

    /** Effective price = catalog minus active coupon discount. */
    public static function effectivePriceCents(Subscription $subscription, ?Plan $plan = null): int
    {
        if (! $plan) {
            $plan = Plan::where('key', $subscription->plan_key)->first();
        }

        $catalog = static::catalogPriceCents($plan, $subscription->interval ?? 'monthly');

        if (! $subscription->coupon_id) {
            return $catalog;
        }

        $coupon = $subscription->relationLoaded('coupon')
            ? $subscription->coupon
            : BillingCoupon::find($subscription->coupon_id);

        if (! $coupon || ! $coupon->isUsable()) {
            return $catalog;
        }

        $discount = app(CouponService::class)->calculateDiscount($coupon, $catalog);

        return max(0, $catalog - $discount);
    }
}
