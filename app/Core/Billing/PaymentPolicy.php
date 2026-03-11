<?php

namespace App\Core\Billing;

use App\Core\Models\Company;

/**
 * ADR-325: Centralized payment method governance.
 *
 * Combines PaymentOrchestrator (DB rules) + PlatformBillingPolicy (SEPA policy)
 * to determine which payment methods are allowed for a given context.
 *
 * Consumers:
 *   - StripePaymentAdapter::createCheckout() → SetupIntent allowed methods
 *   - StripePaymentAdapter::createOnSessionPaymentIntent() → PaymentIntent allowed methods
 *   - PublicPlanController → registration tunnel
 *   - CompanyBillingReadService → billing overview
 */
class PaymentPolicy
{
    /**
     * Allowed payment methods for an existing company.
     * ADR-328: sepa_requires_trial only applies to the registration tunnel,
     * not to existing companies — they keep SEPA if allow_sepa is true.
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public static function allowedMethods(Company $company): array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('is_current', 1)
            ->first();

        return static::allowedMethodsForContext(
            marketKey: $company->market_key,
            planKey: $subscription?->plan_key,
            interval: $subscription?->interval,
        );
    }

    /**
     * Allowed payment methods for the registration tunnel (pre-company).
     * ADR-328: sepa_requires_trial filter applies HERE only — controls whether
     * SEPA is offered during signup based on whether the plan has a trial period.
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public static function allowedMethodsForRegistration(
        string $planKey,
        string $interval,
        string $marketKey,
    ): array {
        $methods = static::allowedMethodsForContext(
            marketKey: $marketKey,
            planKey: $planKey,
            interval: $interval,
        );

        // ADR-328: Apply sepa_requires_trial only for the registration tunnel
        $policy = PlatformBillingPolicy::instance();

        if ($policy->sepa_requires_trial && in_array('sepa_debit', $methods, true)) {
            $plan = \App\Core\Plans\Plan::where('key', $planKey)->first();
            $hasTrial = $plan && $plan->trial_days > 0;

            if (! $hasTrial) {
                $methods = array_values(array_filter($methods, fn ($m) => $m !== 'sepa_debit'));
            }
        }

        if (empty($methods)) {
            $methods = ['card'];
        }

        return $methods;
    }

    /**
     * Core resolution: PaymentOrchestrator rules + global SEPA master switch.
     * Note: sepa_requires_trial is NOT applied here — it only applies in the
     * registration tunnel via allowedMethodsForRegistration().
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public static function allowedMethodsForContext(
        ?string $marketKey = null,
        ?string $planKey = null,
        ?string $interval = null,
    ): array {
        // Step 1: Resolve from DB rules via PaymentOrchestrator
        $resolved = PaymentOrchestrator::resolveMethodsForContext($marketKey, $planKey, $interval);
        $methods = array_column($resolved, 'method_key');

        // Step 2: Apply global SEPA master switch only
        $policy = PlatformBillingPolicy::instance();

        if (in_array('sepa_debit', $methods, true) && ! $policy->allow_sepa) {
            $methods = array_values(array_filter($methods, fn ($m) => $m !== 'sepa_debit'));
        }

        // Guarantee at least 'card'
        if (empty($methods)) {
            $methods = ['card'];
        }

        return array_values(array_unique($methods));
    }
}
