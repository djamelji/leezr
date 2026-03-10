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
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public static function allowedMethods(Company $company): array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('is_current', 1)
            ->first();

        $isTrial = $subscription && $subscription->status === 'trialing';

        return static::allowedMethodsForContext(
            marketKey: $company->market_key,
            planKey: $subscription?->plan_key,
            interval: $subscription?->interval,
            isTrial: $isTrial,
        );
    }

    /**
     * Allowed payment methods for the registration tunnel (pre-company).
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public static function allowedMethodsForRegistration(
        string $planKey,
        string $interval,
        string $marketKey,
    ): array {
        $policy = PlatformBillingPolicy::instance();

        // During registration, trial status depends on plan config
        $plan = \App\Core\Plans\Plan::where('key', $planKey)->first();
        $isTrial = $plan && $plan->trial_days > 0;

        return static::allowedMethodsForContext(
            marketKey: $marketKey,
            planKey: $planKey,
            interval: $interval,
            isTrial: $isTrial,
        );
    }

    /**
     * Core resolution: PaymentOrchestrator rules + SEPA policy filter.
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public static function allowedMethodsForContext(
        ?string $marketKey = null,
        ?string $planKey = null,
        ?string $interval = null,
        bool $isTrial = false,
    ): array {
        // Step 1: Resolve from DB rules via PaymentOrchestrator
        $resolved = PaymentOrchestrator::resolveMethodsForContext($marketKey, $planKey, $interval);
        $methods = array_column($resolved, 'method_key');

        // Step 2: Apply SEPA policy filter
        $policy = PlatformBillingPolicy::instance();

        if (in_array('sepa_debit', $methods, true)) {
            if (! $policy->allow_sepa) {
                $methods = array_values(array_filter($methods, fn ($m) => $m !== 'sepa_debit'));
            } elseif ($policy->sepa_requires_trial && ! $isTrial) {
                $methods = array_values(array_filter($methods, fn ($m) => $m !== 'sepa_debit'));
            }
        }

        // Guarantee at least 'card'
        if (empty($methods)) {
            $methods = ['card'];
        }

        return array_values(array_unique($methods));
    }
}
