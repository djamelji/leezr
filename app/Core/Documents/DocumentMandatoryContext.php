<?php

namespace App\Core\Documents;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;

/**
 * ADR-169 Phase 3: Per-request cache for document mandatory context.
 * Same pattern as Fields\MandatoryContext.
 */
class DocumentMandatoryContext
{
    private static array $cache = [];

    public static function load(int $companyId): array
    {
        if (isset(self::$cache[$companyId])) {
            return self::$cache[$companyId];
        }

        $company = Company::find($companyId);
        $jobdomainKey = $company?->jobdomain_key;

        $activeModules = CompanyModule::where('company_id', $companyId)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        self::$cache[$companyId] = [
            'jobdomain_key' => $jobdomainKey,
            'active_modules' => $activeModules,
        ];

        return self::$cache[$companyId];
    }

    public static function isMandatory(DocumentType $type, array $context, ?array $roleRequiredTags = null): bool
    {
        $rules = $type->validation_rules ?? [];

        // Jobdomain-level mandatory
        $byJobdomains = $rules['required_by_jobdomains'] ?? [];
        if (!empty($byJobdomains) && in_array($context['jobdomain_key'], $byJobdomains)) {
            return true;
        }

        // Module-level mandatory
        $byModules = $rules['required_by_modules'] ?? [];
        if (!empty($byModules) && !empty(array_intersect($byModules, $context['active_modules']))) {
            return true;
        }

        // ADR-170 Phase 4: Tag-based mandatory (sole role-based mechanism)
        if (!empty($rules['tags']) && !empty($roleRequiredTags)
            && !empty(array_intersect($rules['tags'], $roleRequiredTags))) {
            return true;
        }

        return false;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
