<?php

namespace App\Core\Modules;

use App\Core\Models\Company;

/**
 * Read model that builds the full module catalog for a company.
 * Combines platform_modules + company_modules + capabilities + entitlements into a single list.
 */
class ModuleCatalogReadModel
{
    /**
     * Get the full module catalog for a company.
     * Returns all platform modules with their activation status, capabilities, and entitlement info.
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

        $entitlements = EntitlementResolver::allForCompany($company);

        return $platformModules->map(function (PlatformModule $pm) use ($companyModules, $entitlements) {
            $cm = $companyModules->get($pm->key);
            $capabilities = ModuleRegistry::capabilities($pm->key);
            $manifest = ModuleRegistry::definitions()[$pm->key] ?? null;
            $entitlement = $entitlements[$pm->key] ?? ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];

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
                'type' => $manifest?->type ?? 'addon',
                'is_entitled' => $entitlement['entitled'],
                'entitlement_source' => $entitlement['source'],
                'entitlement_reason' => $entitlement['reason'],
                'requires' => $manifest?->requires ?? [],
                'min_plan' => $manifest?->minPlan,
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
