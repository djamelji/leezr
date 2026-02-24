<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Central authority for module activation checks.
 *
 * Admin scope: PlatformModule.is_enabled_globally only (no company context).
 * Company scope: PlatformModule.is_enabled_globally AND CompanyModule.is_enabled_for_company.
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
     * Core modules are always active for any company (only need global enablement).
     * Addon modules require a CompanyModule row with is_enabled_for_company=true.
     */
    public static function isActive(Company $company, string $moduleKey): bool
    {
        $platformModule = PlatformModule::where('key', $moduleKey)->first();

        if (!$platformModule || !$platformModule->is_enabled_globally) {
            return false;
        }

        // Core modules are always active — no per-company toggle
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if ($manifest && $manifest->type === 'core') {
            return true;
        }

        $companyModule = CompanyModule::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->first();

        if (!$companyModule) {
            return false;
        }

        return $companyModule->is_enabled_for_company;
    }

    /**
     * Check if a module is enabled globally (platform level).
     */
    public static function isEnabledGlobally(string $moduleKey): bool
    {
        $platformModule = PlatformModule::where('key', $moduleKey)->first();

        return $platformModule?->is_enabled_globally ?? false;
    }
}
