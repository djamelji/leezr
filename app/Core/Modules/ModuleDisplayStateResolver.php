<?php

namespace App\Core\Modules;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * ADR-163/206: Resolves the commercial display state for a module.
 *
 * Resolution pipeline (strict ordering — first match wins):
 *   1. Hidden manifest       → SYSTEM
 *   2. Globally disabled      → SYSTEM
 *   3. Plan requirement       → LOCKED_PLAN
 *   4. Core module            → INCLUDED
 *   5. Jobdomain default      → INCLUDED
 *   6. Active + entitled      → ACTIVE
 *   7. Entitled               → AVAILABLE
 *   8. addon_pricing ≠ null   → LOCKED_ADDON
 *   9. Fallback               → CONTACT_SALES
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

        // 4. Core modules are always included
        if ($manifest->type === 'core') {
            return ModuleDisplayState::INCLUDED;
        }

        // 5. Module in jobdomain defaults is included
        // ADR-167a: jobdomain is always present
        if (in_array($manifest->key, $jobdomain->default_modules ?? [], true)) {
            return ModuleDisplayState::INCLUDED;
        }

        // 6. Explicitly activated and entitled
        if ($isActive && $entitlement['entitled']) {
            return ModuleDisplayState::ACTIVE;
        }

        // 7. Entitled but not yet activated
        if ($entitlement['entitled']) {
            return ModuleDisplayState::AVAILABLE;
        }

        // 8. ADR-206: Module has addon pricing → purchasable as addon
        if ($pm->addon_pricing !== null) {
            return ModuleDisplayState::LOCKED_ADDON;
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
