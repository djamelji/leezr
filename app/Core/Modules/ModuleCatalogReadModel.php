<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Read model that builds the full module catalog for a company.
 * Combines platform_modules + company_modules + capabilities into a single list.
 */
class ModuleCatalogReadModel
{
    /**
     * Get the full module catalog for a company.
     * Returns all platform modules with their activation status and capabilities.
     */
    public static function forCompany(Company $company): array
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        $platformModules = PlatformModule::whereIn('key', $companyModuleKeys)
            ->orderBy('sort_order')
            ->get();

        $companyModules = CompanyModule::where('company_id', $company->id)
            ->get()
            ->keyBy('module_key');

        return $platformModules->map(function (PlatformModule $pm) use ($companyModules) {
            $cm = $companyModules->get($pm->key);
            $capabilities = ModuleRegistry::capabilities($pm->key);

            $isActive = $pm->is_enabled_globally
                && $cm !== null
                && $cm->is_enabled_for_company;

            return [
                'key' => $pm->key,
                'name' => $pm->name,
                'description' => $pm->description,
                'is_enabled_globally' => $pm->is_enabled_globally,
                'is_enabled_for_company' => $cm?->is_enabled_for_company ?? false,
                'is_active' => $isActive,
                'capabilities' => $capabilities?->toArray() ?? [],
            ];
        })->all();
    }

    /**
     * Get only active modules for a company (for frontend consumption).
     */
    public static function activeForCompany(Company $company): array
    {
        return array_values(array_filter(
            static::forCompany($company),
            fn (array $module) => $module['is_active'],
        ));
    }
}
