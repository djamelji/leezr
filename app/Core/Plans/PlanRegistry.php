<?php

namespace App\Core\Plans;

/**
 * DB-backed plan registry with in-memory cache.
 * Follows ModuleRegistry/JobdomainRegistry sync pattern.
 *
 * seedDefaults() holds hardcoded plan data for seeding.
 * sync() upserts seed data into the plans table.
 * All runtime methods read from DB via Plan model.
 *
 * ADR-101: Refactored from code-defined to DB-driven.
 * definitions() return shape stays strictly identical (prices in dollars)
 * so all callers (EntitlementResolver, PublicPlanController, validation rules) work unchanged.
 */
class PlanRegistry
{
    /** @var array<string, array>|null In-memory cache keyed by plan key */
    private static ?array $cache = null;

    /**
     * Hardcoded seed defaults — used by sync() only.
     * Prices are in DOLLARS (sync() converts to cents for DB storage).
     */
    public static function seedDefaults(): array
    {
        return [
            'starter' => [
                'name' => 'Starter',
                'level' => 0,
                'description' => 'Free tier with core features',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'is_popular' => false,
                'trial_days' => 0,
                'feature_labels' => [
                    'Up to 5 members',
                    'Core modules only',
                    'Community support',
                ],
                'limits' => ['members' => 5],
            ],
            'pro' => [
                'name' => 'Pro',
                'level' => 10,
                'description' => 'Full access to industry modules',
                'price_monthly' => 29,
                'price_yearly' => 290,
                'is_popular' => true,
                'trial_days' => 14,
                'feature_labels' => [
                    'Unlimited members',
                    'All industry modules',
                    'Priority support',
                    'Custom roles',
                ],
                'limits' => ['members' => null],
            ],
            'business' => [
                'name' => 'Business',
                'level' => 20,
                'description' => 'Premium features and addons',
                'price_monthly' => 79,
                'price_yearly' => 790,
                'is_popular' => false,
                'trial_days' => 14,
                'feature_labels' => [
                    'Everything in Pro',
                    'Premium addons',
                    'Dedicated support',
                    'Advanced analytics',
                ],
                'limits' => ['members' => null],
            ],
        ];
    }

    /**
     * Sync seed defaults to DB. Called from SystemSeeder.
     * Idempotent — safe to run multiple times.
     * Does NOT overwrite is_active (preserves admin decisions).
     */
    public static function sync(): void
    {
        foreach (static::seedDefaults() as $key => $def) {
            Plan::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $def['name'],
                    'level' => $def['level'],
                    'description' => $def['description'] ?? '',
                    'price_monthly' => ($def['price_monthly'] ?? 0) * 100,
                    'price_yearly' => ($def['price_yearly'] ?? 0) * 100,
                    'is_popular' => $def['is_popular'] ?? false,
                    'trial_days' => $def['trial_days'] ?? 0,
                    'feature_labels' => $def['feature_labels'] ?? [],
                    'limits' => $def['limits'] ?? [],
                ],
            );
        }

        static::clearCache();
    }

    /**
     * All plan definitions from DB, keyed by plan key.
     * Prices returned in DOLLARS for backward compatibility.
     * Cached per request.
     */
    public static function definitions(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        try {
            $plans = Plan::where('is_active', true)->orderBy('level')->get();
        } catch (\Throwable) {
            // Fallback to seed defaults during migrations / before table exists
            static::$cache = static::seedDefaults();

            return static::$cache;
        }

        if ($plans->isEmpty()) {
            // No plans in DB yet — fallback to seed defaults
            static::$cache = static::seedDefaults();

            return static::$cache;
        }

        $definitions = [];

        foreach ($plans as $plan) {
            $definitions[$plan->key] = [
                'name' => $plan->name,
                'level' => $plan->level,
                'description' => $plan->description,
                'price_monthly' => $plan->priceMonthlyDollars(),
                'price_yearly' => $plan->priceYearlyDollars(),
                'is_popular' => $plan->is_popular,
                'trial_days' => $plan->trial_days ?? 0,
                'feature_labels' => $plan->feature_labels ?? [],
                'limits' => $plan->limits ?? [],
            ];
        }

        static::$cache = $definitions;

        return $definitions;
    }

    /**
     * Plan catalog for public consumption (adds 'key' to each entry).
     */
    public static function publicCatalog(): array
    {
        return collect(static::definitions())
            ->map(fn ($def, $key) => ['key' => $key, ...$def])
            ->values()
            ->all();
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
     * All valid plan keys (active plans only).
     */
    public static function keys(): array
    {
        return array_keys(static::definitions());
    }

    /**
     * All plan keys including inactive plans.
     */
    public static function allKeys(): array
    {
        try {
            return Plan::pluck('key')->all();
        } catch (\Throwable) {
            return array_keys(static::seedDefaults());
        }
    }

    /**
     * Clear the in-memory cache.
     */
    public static function clearCache(): void
    {
        static::$cache = null;
    }
}
