<?php

namespace App\Core\Jobdomains;

use App\Core\Fields\FieldDefinition;

/**
 * Resolves jobdomain presets with optional market overlay.
 *
 * Merge rules:
 * - Modules: array_unique(global + override) - remove
 * - Fields/Documents: merge-by-code (override replaces matching, adds new) - remove codes
 * - Roles: deep merge by key (override adds/replaces role defs) - remove keys
 *
 * Mandatory guards:
 * - Fields: cannot remove fields whose FieldDefinition.required_by_jobdomains contains this jobdomain
 * - Roles: cannot remove roles where is_administrative=true in base preset
 *
 * Static cache: 1 query per (jobdomain_key, market_key) pair, then in-memory.
 */
class JobdomainPresetResolver
{
    private static array $cache = [];

    /**
     * Resolve presets for a (jobdomain, market) pair.
     * Eager-loads overlays in a single query, then merges in-memory.
     */
    public static function resolve(string $jobdomainKey, ?string $marketKey = null): ResolvedPresets
    {
        $cacheKey = "{$jobdomainKey}:{$marketKey}";

        if (isset(static::$cache[$cacheKey])) {
            return static::$cache[$cacheKey];
        }

        $jobdomain = Jobdomain::with('overlays')->where('key', $jobdomainKey)->first();

        if (! $jobdomain) {
            return static::$cache[$cacheKey] = new ResolvedPresets(
                jobdomainKey: $jobdomainKey,
                marketKey: $marketKey,
                modules: [],
                fields: [],
                documents: [],
                roles: [],
            );
        }

        $overlay = $marketKey
            ? $jobdomain->overlays->firstWhere('market_key', $marketKey)
            : null;

        $modules = static::resolveModules(
            $jobdomain->default_modules ?? [],
            $overlay?->override_modules,
            $overlay?->remove_modules,
        );

        $fields = static::resolveFields(
            $jobdomain->default_fields ?? [],
            $overlay?->override_fields,
            $overlay?->remove_fields,
            $jobdomainKey,
        );

        $documents = static::resolveDocuments(
            $jobdomain->default_documents ?? [],
            $overlay?->override_documents,
            $overlay?->remove_documents,
        );

        $roles = static::resolveRoles(
            $jobdomain->default_roles ?? [],
            $overlay?->override_roles,
            $overlay?->remove_roles,
        );

        return static::$cache[$cacheKey] = new ResolvedPresets(
            jobdomainKey: $jobdomainKey,
            marketKey: $marketKey,
            modules: $modules,
            fields: $fields,
            documents: $documents,
            roles: $roles,
        );
    }

    /**
     * Modules: union minus remove. No mandatory guard (any module can be per-market excluded).
     */
    public static function resolveModules(array $global, ?array $override, ?array $remove): array
    {
        $merged = array_values(array_unique(array_merge($global, $override ?? [])));

        return array_values(array_diff($merged, $remove ?? []));
    }

    /**
     * Fields: merge-by-code, then remove. Mandatory guard: fields required by jobdomain cannot be removed.
     */
    public static function resolveFields(array $global, ?array $override, ?array $remove, string $jobdomainKey): array
    {
        $result = collect($global)->keyBy('code');

        foreach ($override ?? [] as $field) {
            $result->put($field['code'], $field);
        }

        $removeCodes = $remove ?? [];

        if (! empty($removeCodes)) {
            $mandatoryCodes = static::mandatoryFieldCodes($removeCodes, $jobdomainKey);
            $removeCodes = array_values(array_diff($removeCodes, $mandatoryCodes));
        }

        foreach ($removeCodes as $code) {
            $result->forget($code);
        }

        return $result->sortBy('order')->values()->all();
    }

    /**
     * Documents: merge-by-code, then remove. No mandatory guard for now (Phase B may add).
     */
    public static function resolveDocuments(array $global, ?array $override, ?array $remove): array
    {
        $result = collect($global)->keyBy('code');

        foreach ($override ?? [] as $doc) {
            $result->put($doc['code'], $doc);
        }

        foreach ($remove ?? [] as $code) {
            $result->forget($code);
        }

        return $result->sortBy('order')->values()->all();
    }

    /**
     * Roles: deep merge by key, then remove. Mandatory guard: administrative roles cannot be removed.
     */
    public static function resolveRoles(array $global, ?array $override, ?array $remove): array
    {
        $result = $global;

        foreach ($override ?? [] as $key => $roleDef) {
            if (isset($result[$key])) {
                $result[$key] = array_replace_recursive($result[$key], $roleDef);
            } else {
                $result[$key] = $roleDef;
            }
        }

        foreach ($remove ?? [] as $key) {
            if (isset($result[$key]) && ! empty($result[$key]['is_administrative'])) {
                continue; // Mandatory guard: cannot remove administrative roles
            }
            unset($result[$key]);
        }

        return $result;
    }

    /**
     * Get field codes that are mandatory for a jobdomain (required_by_jobdomains).
     */
    private static function mandatoryFieldCodes(array $codes, string $jobdomainKey): array
    {
        return FieldDefinition::whereNull('company_id')
            ->whereIn('code', $codes)
            ->get()
            ->filter(function (FieldDefinition $fd) use ($jobdomainKey) {
                $rules = $fd->validation_rules ?? [];

                return in_array($jobdomainKey, $rules['required_by_jobdomains'] ?? [], true);
            })
            ->pluck('code')
            ->toArray();
    }

    /** Flush cache (for tests and after overlay mutations). */
    public static function clearCache(): void
    {
        static::$cache = [];
    }
}
