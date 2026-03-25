<?php

namespace App\Core\Documents;

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

        $activeModules = CompanyModule::where('company_id', $companyId)
            ->where('is_enabled_for_company', true)
            ->pluck('module_key')
            ->toArray();

        self::$cache[$companyId] = [
            'active_modules' => $activeModules,
        ];

        return self::$cache[$companyId];
    }

    /**
     * ADR-389: Mandatory is determined by module gating + tag gating only.
     * Jobdomain coupling removed — obligation is now set via required_override
     * at jobdomain assignment time (JobdomainGate::assignToCompany).
     */
    public static function isMandatory(DocumentType $type, array $context, ?array $roleRequiredTags = null): bool
    {
        $rules = $type->validation_rules ?? [];

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
