<?php

namespace App\Core\Modules\Pricing;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use RuntimeException;

/**
 * ADR-206: Pricing policy invariant enforcement for modules.
 *
 * Validates that addon_pricing configuration doesn't create billing hazards.
 * Called at boot time (service provider) and on config update (ModuleController).
 *
 * Rules:
 *   1. type === 'core' → addon_pricing must be null
 *   2. type === 'internal' → addon_pricing must be null
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
        static::assertCoreModulesNotAddon();
        static::assertInternalModulesNotAddon();
    }

    /**
     * Assert a single module's addon_pricing is valid.
     * Used after individual module config updates.
     *
     * @throws RuntimeException on violation
     */
    public static function assertAddonPricing(string $moduleKey, ?array $addonPricing): void
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest) {
            return;
        }

        // Rule 1: core → addon_pricing must be null
        if ($manifest->type === 'core' && $addonPricing !== null) {
            throw new RuntimeException(
                "Pricing invariant: core module '{$moduleKey}' cannot have addon_pricing."
            );
        }

        // Rule 2: internal → addon_pricing must be null
        if ($manifest->type === 'internal' && $addonPricing !== null) {
            throw new RuntimeException(
                "Pricing invariant: internal module '{$moduleKey}' cannot have addon_pricing."
            );
        }
    }

    // ─── Bulk invariants ────────────────────────────────────

    /**
     * Rule 1: Core modules cannot have addon_pricing.
     */
    private static function assertCoreModulesNotAddon(): void
    {
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->type !== 'core') {
                continue;
            }

            $pm = PlatformModule::where('key', $key)->first();

            if ($pm && $pm->addon_pricing !== null) {
                throw new RuntimeException(
                    "Pricing invariant: core module '{$key}' cannot have addon_pricing."
                );
            }
        }
    }

    /**
     * Rule 2: Internal modules cannot have addon_pricing.
     */
    private static function assertInternalModulesNotAddon(): void
    {
        foreach (ModuleRegistry::definitions() as $key => $manifest) {
            if ($manifest->type !== 'internal') {
                continue;
            }

            $pm = PlatformModule::where('key', $key)->first();

            if ($pm && $pm->addon_pricing !== null) {
                throw new RuntimeException(
                    "Pricing invariant: internal module '{$key}' cannot have addon_pricing."
                );
            }
        }
    }
}
