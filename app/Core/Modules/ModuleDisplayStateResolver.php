<?php

namespace App\Core\Modules;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * ADR-163: Resolves the commercial display state for a module.
 *
 * Resolution pipeline (strict ordering — first match wins):
 *   1. Hidden manifest      → SYSTEM
 *   2. Globally disabled     → SYSTEM
 *   3. Plan requirement      → LOCKED_PLAN (even if activation record exists)
 *   4. Addon not entitled    → LOCKED_ADDON
 *   5. Core module           → INCLUDED
 *   6. Jobdomain default     → INCLUDED
 *   7. Active + entitled     → ACTIVE
 *   8. Entitled              → AVAILABLE
 *   9. Fallback              → CONTACT_SALES
 */
class ModuleDisplayStateResolver
{
    /**
     * Resolve the display state for a single module.
     */
    public static function resolve(
        ModuleManifest $manifest,
        PlatformModule $pm,
        array $entitlement,
        bool $isActive,
        Jobdomain $jobdomain,
        string $companyPlan,
    ): ModuleDisplayState {
        $minPlan = $pm->min_plan_override ?? $manifest->minPlan;
        $pricingMode = $pm->pricing_mode ?? 'included';

        // 1. Hidden modules are never exposed
        if ($manifest->hidden) {
            return ModuleDisplayState::SYSTEM;
        }

        // 2. Globally disabled modules are system-level hidden
        if (! $pm->is_enabled_globally) {
            return ModuleDisplayState::SYSTEM;
        }

        // 3. Plan lock takes priority over activation
        if ($minPlan !== null && ! PlanRegistry::meetsRequirement($companyPlan, $minPlan)) {
            return ModuleDisplayState::LOCKED_PLAN;
        }

        // 4. Addon not entitled
        if ($pricingMode === 'addon' && ! $entitlement['entitled']) {
            return ModuleDisplayState::LOCKED_ADDON;
        }

        // 5. Core modules are always included
        if ($manifest->type === 'core') {
            return ModuleDisplayState::INCLUDED;
        }

        // 6. Module in jobdomain defaults is included
        // ADR-167a: jobdomain is always present
        if (in_array($manifest->key, $jobdomain->default_modules ?? [], true)) {
            return ModuleDisplayState::INCLUDED;
        }

        // 7. Explicitly activated and entitled
        if ($isActive && $entitlement['entitled']) {
            return ModuleDisplayState::ACTIVE;
        }

        // 8. Entitled but not yet activated
        if ($entitlement['entitled']) {
            return ModuleDisplayState::AVAILABLE;
        }

        // 9. Fallback — module exists but no clear path to activation
        return ModuleDisplayState::CONTACT_SALES;
    }

    /**
     * Resolve display states for all modules in a company catalog.
     *
     * @return array<string, ModuleDisplayState> Keyed by module key
     */
    public static function resolveAll(
        Company $company,
        array $entitlements,
        array $activationMap,
    ): array {
        $jobdomain = $company->jobdomain;
        $companyPlan = CompanyEntitlements::planKey($company);
        $definitions = ModuleRegistry::definitions();
        $result = [];

        $platformModules = PlatformModule::whereIn('key', array_keys(ModuleRegistry::forScope('company')))
            ->get()
            ->keyBy('key');

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            $pm = $platformModules->get($key);
            if (! $pm) {
                continue;
            }

            $entitlement = $entitlements[$key] ?? ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];
            $isActive = $activationMap[$key] ?? false;

            $result[$key] = static::resolve($manifest, $pm, $entitlement, $isActive, $jobdomain, $companyPlan);
        }

        return $result;
    }
}
