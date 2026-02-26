<?php

namespace App\Core\Billing;

/**
 * Resolves which payment methods are available for a given business context.
 *
 * Algorithm:
 * 1. Load active rules from platform_payment_method_rules
 * 2. Filter by context (market_key, plan_key, interval — null rule dimension matches any)
 * 3. Score specificity: +1 per non-null dimension in the rule
 * 4. Sort: specificity DESC, then priority DESC
 * 5. Deduplicate per method_key (keep highest-scoring)
 * 6. Filter: provider must be installed+active in platform_payment_modules
 * 7. Return [{method_key, provider_key, priority}]
 */
class PaymentOrchestrator
{
    /**
     * Resolve available payment methods for a given context.
     *
     * @return array<array{method_key: string, provider_key: string, priority: int}>
     */
    public static function resolveMethodsForContext(
        ?string $marketKey = null,
        ?string $planKey = null,
        ?string $interval = null,
    ): array {
        $rules = static::matchAndScore($marketKey, $planKey, $interval);
        $rules = static::deduplicate($rules);

        // Filter: only keep methods whose provider is active AND installed
        $activeProviderKeys = PlatformPaymentModule::active()
            ->pluck('provider_key')
            ->all();

        return array_values(array_filter($rules, function ($rule) use ($activeProviderKeys) {
            return in_array($rule['provider_key'], $activeProviderKeys, true);
        }));
    }

    /**
     * Preview: same as resolveMethodsForContext but without provider active check.
     * Used by platform admin to see what WOULD be available.
     *
     * @return array<array{method_key: string, provider_key: string, priority: int, specificity: int}>
     */
    public static function previewMethodsForContext(
        ?string $marketKey = null,
        ?string $planKey = null,
        ?string $interval = null,
    ): array {
        $rules = static::matchAndScore($marketKey, $planKey, $interval);

        return array_values(static::deduplicate($rules));
    }

    /**
     * Match rules against context and score specificity.
     *
     * @return array<array{method_key: string, provider_key: string, priority: int, specificity: int}>
     */
    private static function matchAndScore(
        ?string $marketKey,
        ?string $planKey,
        ?string $interval,
    ): array {
        $allRules = PlatformPaymentMethodRule::active()->get();
        $matched = [];

        foreach ($allRules as $rule) {
            // A rule matches if each of its non-null dimensions matches the context
            if ($rule->market_key !== null && $rule->market_key !== $marketKey) {
                continue;
            }
            if ($rule->plan_key !== null && $rule->plan_key !== $planKey) {
                continue;
            }
            if ($rule->interval !== null && $rule->interval !== $interval) {
                continue;
            }

            // Score: +1 per non-null dimension in the rule
            $specificity = 0;
            if ($rule->market_key !== null) {
                $specificity++;
            }
            if ($rule->plan_key !== null) {
                $specificity++;
            }
            if ($rule->interval !== null) {
                $specificity++;
            }

            $matched[] = [
                'method_key' => $rule->method_key,
                'provider_key' => $rule->provider_key,
                'priority' => $rule->priority,
                'specificity' => $specificity,
            ];
        }

        // Sort: specificity DESC, then priority DESC
        usort($matched, function ($a, $b) {
            if ($a['specificity'] !== $b['specificity']) {
                return $b['specificity'] - $a['specificity'];
            }

            return $b['priority'] - $a['priority'];
        });

        return $matched;
    }

    /**
     * Deduplicate: per method_key, keep the highest-scoring rule.
     * Since the array is already sorted by score, first occurrence wins.
     */
    private static function deduplicate(array $rules): array
    {
        $seen = [];
        $result = [];

        foreach ($rules as $rule) {
            if (isset($seen[$rule['method_key']])) {
                continue;
            }

            $seen[$rule['method_key']] = true;
            $result[] = $rule;
        }

        return $result;
    }
}
