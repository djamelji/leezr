<?php

namespace App\Core\Modules;

use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * Computes module entitlements dynamically — no entitlement table.
 *
 * Resolution order:
 *   1. Core gate — type 'core' always entitled, cannot be disabled
 *   2. Plan gate — company plan must meet module's min_plan
 *   3. Compat gate — company jobdomain must be in compatible_jobdomains
 *   4. Source gate — module must be available via jobdomain (or future addon purchase)
 */
class EntitlementResolver
{
    /**
     * Check if a company is entitled to a module.
     *
     * @return array{entitled: bool, source: ?string, reason: ?string}
     *   source: 'core' | 'jobdomain' | null
     *   reason: 'unknown_module' | 'plan_required' | 'incompatible_jobdomain' | 'not_available' | null
     */
    public static function check(Company $company, string $moduleKey): array
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest || $manifest->scope !== 'company') {
            return ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];
        }

        // Gate 1: Core modules are always entitled
        if ($manifest->type === 'core') {
            return ['entitled' => true, 'source' => 'core', 'reason' => null];
        }

        // Gate 2: Plan check
        if ($manifest->minPlan !== null) {
            if (!PlanRegistry::meetsRequirement($company->plan_key ?? 'starter', $manifest->minPlan)) {
                return ['entitled' => false, 'source' => null, 'reason' => 'plan_required'];
            }
        }

        // Gate 3: Jobdomain compatibility
        if ($manifest->compatibleJobdomains !== null) {
            $jobdomain = $company->jobdomain;

            if (!$jobdomain || !in_array($jobdomain->key, $manifest->compatibleJobdomains, true)) {
                return ['entitled' => false, 'source' => null, 'reason' => 'incompatible_jobdomain'];
            }
        }

        // Gate 4: Source — is the module available via jobdomain?
        $jobdomain = $company->jobdomain;

        if ($jobdomain) {
            $defaultModules = $jobdomain->default_modules ?? [];

            if (in_array($moduleKey, $defaultModules, true)) {
                return ['entitled' => true, 'source' => 'jobdomain', 'reason' => null];
            }
        }

        // Future: check addon purchases here

        return ['entitled' => false, 'source' => null, 'reason' => 'not_available'];
    }

    /**
     * Entitlement map for all company-scope modules.
     *
     * @return array<string, array{entitled: bool, source: ?string, reason: ?string}>
     */
    public static function allForCompany(Company $company): array
    {
        $result = [];

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            $result[$key] = static::check($company, $key);
        }

        return $result;
    }
}
