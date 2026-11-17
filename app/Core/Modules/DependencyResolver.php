<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Validates module dependencies for activation and deactivation.
 *
 * ADR-821 — thin compatibility façade : the single resolution lives in ModuleDependencyResolver (transitive,
 * cycles, unknown modules). Kept for its existing API.
 */
class DependencyResolver
{
    /** @return array{can_activate: bool, missing: string[]} required modules (transitively) not active yet */
    public static function canActivate(Company $company, string $moduleKey): array
    {
        $missing = array_values(array_filter(ModuleDependencyResolver::requiresOf($moduleKey), fn ($k) => ! ModuleGate::isActive($company, $k)));

        return ['can_activate' => empty($missing), 'missing' => $missing];
    }

    /** @return array{can_deactivate: bool, dependents: string[]} active modules needing this one (transitively) */
    public static function canDeactivate(Company $company, string $moduleKey): array
    {
        $active = array_keys(array_filter(ModuleRegistry::forScope('company'), fn ($m, $k) => ModuleGate::isActive($company, $k), ARRAY_FILTER_USE_BOTH));
        $dependents = ModuleDependencyResolver::dependents($moduleKey, $active);

        return ['can_deactivate' => empty($dependents), 'dependents' => $dependents];
    }
}
