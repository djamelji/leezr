<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Central authority for module activation checks.
 * Source of truth: platform_modules.is_enabled_globally AND company_modules row exists AND is_enabled_for_company.
 */
class ModuleGate
{
    /**
     * Check if a module is active for a given company.
     */
    public static function isActive(Company $company, string $moduleKey): bool
    {
        $platformModule = PlatformModule::where('key', $moduleKey)->first();

        if (!$platformModule || !$platformModule->is_enabled_globally) {
            return false;
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
