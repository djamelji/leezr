<?php

namespace App\Core\Fields;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;

/**
 * Shared helper for mandatory field computation.
 *
 * Cached per-request: load() does max 2 queries, then serves from static cache.
 * isMandatory() does 0 queries (in-memory computation).
 */
class MandatoryContext
{
    private static array $cache = [];

    public static function load(?int $companyId): array
    {
        if (!$companyId) {
            return ['jobdomain_key' => null, 'active_modules' => []];
        }

        if (isset(static::$cache[$companyId])) {
            return static::$cache[$companyId];
        }

        $jobdomainKey = Company::where('id', $companyId)->value('jobdomain_key');

        $activeModules = CompanyModule::where('company_id', $companyId)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        static::$cache[$companyId] = [
            'jobdomain_key' => $jobdomainKey,
            'active_modules' => $activeModules,
        ];

        return static::$cache[$companyId];
    }

    public static function isMandatory(FieldDefinition $definition, array $context, ?array $roleRequiredTags = null): bool
    {
        $rules = $definition->validation_rules ?? [];

        if (!empty($rules['required_by_jobdomains']) && $context['jobdomain_key']
            && in_array($context['jobdomain_key'], $rules['required_by_jobdomains'])) {
            return true;
        }

        if (!empty($rules['required_by_modules'])
            && !empty(array_intersect($rules['required_by_modules'], $context['active_modules']))) {
            return true;
        }

        // ADR-170 Phase 4: Tag-based mandatory (sole role-based mechanism)
        if (!empty($rules['tags']) && !empty($roleRequiredTags)
            && !empty(array_intersect($rules['tags'], $roleRequiredTags))) {
            return true;
        }

        return false;
    }

    /** Flush cache (for tests). */
    public static function flush(): void
    {
        static::$cache = [];
    }
}
