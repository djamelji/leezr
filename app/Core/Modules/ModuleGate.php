<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Central authority for module activation checks.
 *
 * Admin scope: PlatformModule.is_enabled_globally only (no company context).
 * Company scope: Manifest → Global → Core → EntitlementResolver → ActivationReasons (ADR-204).
 */
class ModuleGate
{
    /**
     * Unified activation check for any scope.
     *
     * Admin modules require only global enablement.
     * Company modules require global enablement + company-level activation.
     */
    public static function isActiveForScope(string $moduleKey, ?Company $company = null): bool
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest) {
            return false;
        }

        if ($manifest->scope === 'admin') {
            return static::isEnabledGlobally($moduleKey);
        }

        if ($manifest->scope === 'company') {
            if (!$company) {
                return false;
            }

            return static::isActive($company, $moduleKey);
        }

        return false;
    }

    /**
     * Check if a module is active for a given company.
     *
     * Evaluation order (strict — ADR-204):
     *   1. Manifest lookup (ModuleRegistry — in-memory)
     *   2. Global enablement (PlatformModule)
     *   3. Core bypass
     *   4. EntitlementResolver::check() (dynamic — never stale)
     *   5. ActivationReasons exists() (source of truth — NOT the cache)
     */
    public static function isActive(Company $company, string $moduleKey): bool
    {
        // 1. Manifest lookup
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest) {
            return false;
        }

        // 2. Global enablement
        $platformModule = PlatformModule::where('key', $moduleKey)->first();

        if (!$platformModule || !$platformModule->is_enabled_globally) {
            return false;
        }

        // 3. Core modules bypass
        if ($manifest->type === 'core') {
            return true;
        }

        // 4. Entitlement check (source of truth)
        $entitlement = EntitlementResolver::check($company, $moduleKey, $platformModule);

        if (!$entitlement['entitled']) {
            return false;
        }

        // 5. Activation reason (source of activation)
        return CompanyModuleActivationReason::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->exists();
    }

    /**
     * Check if a module is enabled globally (platform level).
     */
    public static function isEnabledGlobally(string $moduleKey): bool
    {
        $platformModule = PlatformModule::where('key', $moduleKey)->first();

        if ($platformModule) {
            return $platformModule->is_enabled_globally;
        }

        // No row yet (before sync) — known modules default to enabled
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        return $manifest !== null;
    }
}
