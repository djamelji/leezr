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
            return ['jobdomain_key' => null, 'active_modules' => [], 'explicit_only' => false];
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
            // ADR-820 : société au modèle cible (autorité « poste ») — ni le métier ni le rôle d'accès ne rendent
            // un champ obligatoire ; seuls les modules actifs (besoin déclaré) et les exigences configurées le font.
            'explicit_only' => \App\Core\Requirements\RequirementAuthority::isPosition($companyId),
            // ADR-861 : a module requires its ACTIVITY fields (required_by_modules + tags) only from who exercises it
            'function_scoped' => FunctionActivity::isFunctionScoped($companyId),
            'company_id' => $companyId,
        ];

        return static::$cache[$companyId];
    }

    /**
     * @param  ?array  $functionTags  ADR-861 — the person's activity tags (FunctionActivity) when the caller knows the person ;
     *                               null = person unknown → the access role's tags (ROLE authority) or nothing (POSITION authority)
     */
    public static function isMandatory(FieldDefinition $definition, array $context, ?array $roleRequiredTags = null, ?array $functionTags = null): bool
    {
        $rules = $definition->validation_rules ?? [];

        $explicitOnly = (bool) ($context['explicit_only'] ?? false);

        if (!$explicitOnly && !empty($rules['required_by_jobdomains']) && $context['jobdomain_key']
            && in_array($context['jobdomain_key'], $rules['required_by_jobdomains'])) {
            return true;
        }

        if (!empty($rules['required_by_modules'])
            && !empty(array_intersect($rules['required_by_modules'], $context['active_modules']))) {
            if (empty($context['function_scoped']) || empty($rules['tags'])) {
                return true; // historical : the active module requires the field from everyone
            }
            $who = $functionTags ?? ($explicitOnly ? [] : (array) $roleRequiredTags);
            if (!empty(array_intersect($rules['tags'], $who))) {
                return true;
            }
        }

        // ADR-170 Phase 4: Tag-based mandatory (sole role-based mechanism)
        if (!$explicitOnly && !empty($rules['tags']) && !empty($roleRequiredTags)
            && !empty(array_intersect($rules['tags'], $roleRequiredTags))) {
            return true;
        }

        return false;
    }

    /** Flush cache (for tests). */
    public static function flush(): void
    {
        static::$cache = [];
        FunctionActivity::flush();
    }
}
