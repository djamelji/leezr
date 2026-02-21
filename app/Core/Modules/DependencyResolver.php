<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Validates module dependencies for activation and deactivation.
 *
 * - canActivate: checks all required modules are active before enabling
 * - canDeactivate: checks no active module depends on this one before disabling
 */
class DependencyResolver
{
    /**
     * Can this module be activated? Checks all required modules are active.
     *
     * @return array{can_activate: bool, missing: string[]}
     */
    public static function canActivate(Company $company, string $moduleKey): array
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest || empty($manifest->requires)) {
            return ['can_activate' => true, 'missing' => []];
        }

        $missing = [];

        foreach ($manifest->requires as $requiredKey) {
            if (!ModuleGate::isActive($company, $requiredKey)) {
                $missing[] = $requiredKey;
            }
        }

        return ['can_activate' => empty($missing), 'missing' => $missing];
    }

    /**
     * Can this module be deactivated? Checks no active module depends on it.
     *
     * @return array{can_deactivate: bool, dependents: string[]}
     */
    public static function canDeactivate(Company $company, string $moduleKey): array
    {
        $dependents = [];

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            if (in_array($moduleKey, $manifest->requires, true)
                && ModuleGate::isActive($company, $key)) {
                $dependents[] = $key;
            }
        }

        return ['can_deactivate' => empty($dependents), 'dependents' => $dependents];
    }
}
