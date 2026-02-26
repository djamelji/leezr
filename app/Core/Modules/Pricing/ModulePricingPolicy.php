<?php

namespace App\Core\Modules\Pricing;

use App\Core\Modules\DependencyGraph;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use RuntimeException;

/**
 * Pricing policy invariant enforcement for modules.
 *
 * Validates that the pricing configuration does not create billing hazards.
 * Called at boot time (service provider) and on config update (ModuleController).
 *
 * Rules:
 *   1. A module that is requiredBy at least one other module → pricing_mode ≠ 'addon'
 *   2. type === 'core' → pricing_mode ∈ ['included', 'internal']
 *   3. type === 'internal' → pricing_mode === 'internal'
 *   4. No transitive require may be independently billable (addon)
 */
class ModulePricingPolicy
{
    /**
     * Assert all pricing invariants for the entire module catalog.
     *
     * @throws RuntimeException on first violation
     */
    public static function assertInvariants(): void
    {
        static::assertRequiredModulesNotAddon();
        static::assertCoreModulesNotAddon();
        static::assertInternalModulesAreInternal();
        static::assertTransitiveRequiresNotAddon();
    }

    /**
     * Assert a single module's pricing is valid.
     * Used after individual module config updates.
     *
     * @throws RuntimeException on violation
     */
    public static function assertModuleInvariant(string $moduleKey): void
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest) {
            return;
        }

        $platformModule = PlatformModule::where('key', $moduleKey)->first();

        if (!$platformModule) {
            return;
        }

        $pricingMode = $platformModule->pricing_mode;

        // Rule 1: required by others → not addon
        if ($pricingMode === 'addon') {
            $dependents = DependencyGraph::requiredBy($moduleKey);

            if (!empty($dependents)) {
                $list = implode(', ', $dependents);

                throw new RuntimeException(
                    "Pricing invariant: module '{$moduleKey}' is required by [{$list}] "
                    . "and cannot have pricing_mode='addon'."
                );
            }
        }

        // Rule 2: core → not addon
        if ($manifest->type === 'core' && $pricingMode === 'addon') {
            throw new RuntimeException(
                "Pricing invariant: core module '{$moduleKey}' cannot have pricing_mode='addon'."
            );
        }

        // Rule 3: internal → must be internal
        if ($manifest->type === 'internal' && $pricingMode !== null && $pricingMode !== 'internal') {
            throw new RuntimeException(
                "Pricing invariant: internal module '{$moduleKey}' must have pricing_mode='internal'."
            );
        }

        // Rule 4: check this module's transitive requires are not addon
        $closure = DependencyGraph::requiresClosure($moduleKey);

        foreach ($closure as $reqKey) {
            $reqPm = PlatformModule::where('key', $reqKey)->first();

            if ($reqPm && $reqPm->pricing_mode === 'addon') {
                throw new RuntimeException(
                    "Pricing invariant: module '{$moduleKey}' transitively requires '{$reqKey}' "
                    . "which has pricing_mode='addon'. Required modules cannot be independently billable."
                );
            }
        }
    }

    /**
     * Assert a proposed pricing_mode is valid for a module.
     * Used before persisting config updates to prevent violations.
     *
     * @throws RuntimeException on violation
     */
    public static function assertProposedPricingMode(string $moduleKey, ?string $pricingMode): void
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest) {
            return;
        }

        // Rule 1: required by others → not addon
        if ($pricingMode === 'addon') {
            $dependents = DependencyGraph::requiredBy($moduleKey);

            if (!empty($dependents)) {
                $list = implode(', ', $dependents);

                throw new RuntimeException(
                    "Pricing invariant: module '{$moduleKey}' is required by [{$list}] "
                    . "and cannot have pricing_mode='addon'."
                );
            }
        }

        // Rule 2: core → not addon
        if ($manifest->type === 'core' && $pricingMode === 'addon') {
            throw new RuntimeException(
                "Pricing invariant: core module '{$moduleKey}' cannot have pricing_mode='addon'."
            );
        }

        // Rule 3: internal → must be internal
        if ($manifest->type === 'internal' && $pricingMode !== null && $pricingMode !== 'internal') {
            throw new RuntimeException(
                "Pricing invariant: internal module '{$moduleKey}' must have pricing_mode='internal'."
            );
        }
    }

    // ─── Bulk invariants ────────────────────────────────────

    /**
     * Rule 1: A module required by at least one other module cannot be addon.
     */
    private static function assertRequiredModulesNotAddon(): void
    {
        $definitions = ModuleRegistry::definitions();
        $requiredKeys = [];

        foreach ($definitions as $key => $manifest) {
            foreach ($manifest->requires as $reqKey) {
                $requiredKeys[$reqKey][] = $key;
            }
        }

        foreach ($requiredKeys as $requiredKey => $dependentKeys) {
            $pm = PlatformModule::where('key', $requiredKey)->first();

            if (!$pm) {
                continue;
            }

            if ($pm->pricing_mode === 'addon') {
                $list = implode(', ', $dependentKeys);

                throw new RuntimeException(
                    "Pricing invariant: module '{$requiredKey}' is required by [{$list}] "
                    . "but has pricing_mode='addon'."
                );
            }
        }
    }

    /**
     * Rule 2: Core modules cannot be addon-priced.
     */
    private static function assertCoreModulesNotAddon(): void
    {
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->type !== 'core') {
                continue;
            }

            $pm = PlatformModule::where('key', $key)->first();

            if ($pm && $pm->pricing_mode === 'addon') {
                throw new RuntimeException(
                    "Pricing invariant: core module '{$key}' cannot have pricing_mode='addon'."
                );
            }
        }
    }

    /**
     * Rule 3: Internal modules must have pricing_mode='internal'.
     */
    private static function assertInternalModulesAreInternal(): void
    {
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->type !== 'internal') {
                continue;
            }

            $pm = PlatformModule::where('key', $key)->first();

            if ($pm && $pm->pricing_mode !== null && $pm->pricing_mode !== 'internal') {
                throw new RuntimeException(
                    "Pricing invariant: internal module '{$key}' must have pricing_mode='internal', "
                    . "got '{$pm->pricing_mode}'."
                );
            }
        }
    }

    /**
     * Rule 4: No transitive require of any module may be addon-priced.
     */
    private static function assertTransitiveRequiresNotAddon(): void
    {
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if (empty($manifest->requires)) {
                continue;
            }

            $closure = DependencyGraph::requiresClosure($key);

            foreach ($closure as $reqKey) {
                $pm = PlatformModule::where('key', $reqKey)->first();

                if ($pm && $pm->pricing_mode === 'addon') {
                    throw new RuntimeException(
                        "Pricing invariant: module '{$key}' transitively requires '{$reqKey}' "
                        . "which has pricing_mode='addon'."
                    );
                }
            }
        }
    }
}
