<?php

namespace App\Core\Plans;

/**
 * Declarative registry of plan tiers with numeric levels.
 * Code-defined, not DB entities. Level comparison is the only operation.
 * Billing (Stripe, subscriptions) is deferred per ADR-011.
 */
class PlanRegistry
{
    public static function definitions(): array
    {
        return [
            'starter' => ['name' => 'Starter', 'level' => 0, 'description' => 'Free tier with core features'],
            'pro' => ['name' => 'Pro', 'level' => 10, 'description' => 'Full access to industry modules'],
            'business' => ['name' => 'Business', 'level' => 20, 'description' => 'Premium features and addons'],
        ];
    }

    /**
     * Get the numeric level for a plan key (0 if unknown).
     */
    public static function level(string $planKey): int
    {
        return static::definitions()[$planKey]['level'] ?? 0;
    }

    /**
     * Compare: is the company's plan >= the required plan?
     */
    public static function meetsRequirement(string $companyPlan, string $requiredPlan): bool
    {
        return static::level($companyPlan) >= static::level($requiredPlan);
    }

    /**
     * All valid plan keys.
     */
    public static function keys(): array
    {
        return array_keys(static::definitions());
    }
}
