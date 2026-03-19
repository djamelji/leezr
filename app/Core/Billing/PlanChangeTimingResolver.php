<?php

namespace App\Core\Billing;

use App\Core\Plans\PlanRegistry;

/**
 * ADR-362: Resolves plan change timing from policy + subscription state.
 *
 * Extracted from SubscriptionMutationController.
 * Encapsulates the ADR-287 timing resolution logic:
 *   - Interval-only change → interval_change_timing
 *   - Upgrade → upgrade_timing
 *   - Downgrade → downgrade_timing
 *   - Trial + end_of_period → override to immediate
 */
class PlanChangeTimingResolver
{
    /**
     * Resolve the effective timing for a plan change.
     *
     * @return array{timing: string, is_upgrade: bool, is_interval_change: bool}
     */
    public static function resolve(
        Subscription $subscription,
        string $toPlanKey,
        string $toInterval,
        PlatformBillingPolicy $policy,
    ): array {
        $isIntervalChange = $subscription->plan_key === $toPlanKey;
        $isUpgrade = PlanRegistry::level($toPlanKey) > PlanRegistry::level($subscription->plan_key);

        $policyTiming = $isIntervalChange
            ? ($policy->interval_change_timing ?? 'immediate')
            : ($isUpgrade ? $policy->upgrade_timing : $policy->downgrade_timing);

        // ADR-287: end_of_period is meaningless during trial → override to immediate
        if ($subscription->status === 'trialing' && $policyTiming === 'end_of_period') {
            $policyTiming = 'immediate';
        }

        return [
            'timing' => $policyTiming,
            'is_upgrade' => $isUpgrade,
            'is_interval_change' => $isIntervalChange,
        ];
    }
}
