<?php

namespace App\Core\Markets;

/**
 * Merges translations from DB bundles + market overrides.
 *
 * Fallback chain (applied by the API consumer / frontend):
 *   market overrides > DB bundles > JSON locale > JSON en
 *
 * This repository handles the DB layers (bundles + overrides).
 * The static JSON files are handled by vue-i18n on the frontend.
 *
 * ADR-104: International Market Engine.
 */
class TranslationRepository
{
    /**
     * Returns merged translations for a locale, optionally filtered by namespace.
     * Market overrides are applied on top if a market key is provided.
     *
     * @return array Keyed by namespace when no specific namespace, or flat translations when namespace given.
     */
    public static function bundle(string $locale, ?string $namespace = null, ?string $marketKey = null): array
    {
        $query = TranslationBundle::where('locale', $locale);

        if ($namespace) {
            $query->where('namespace', $namespace);
        }

        $result = [];

        foreach ($query->get() as $bundle) {
            $result[$bundle->namespace] = $bundle->translations;
        }

        // Apply market overrides
        if ($marketKey) {
            $overrides = TranslationOverride::where('market_key', $marketKey)
                ->where('locale', $locale)
                ->when($namespace, fn ($q) => $q->where('namespace', $namespace))
                ->get();

            foreach ($overrides as $override) {
                data_set($result, "{$override->namespace}.{$override->key}", $override->value);
            }
        }

        // If a specific namespace was requested, return only that namespace's translations
        return $namespace ? ($result[$namespace] ?? []) : $result;
    }

    /**
     * Compute a diff between incoming translations and current DB state.
     * Used for import preview (dry-run).
     *
     * @return array{added: array, changed: array, removed: array}
     */
    public static function diff(string $locale, string $namespace, array $incoming): array
    {
        $current = TranslationBundle::where('locale', $locale)
            ->where('namespace', $namespace)
            ->first();

        $currentTranslations = $current?->translations ?? [];
        $flatCurrent = self::flattenArray($currentTranslations);
        $flatIncoming = self::flattenArray($incoming);

        $added = array_diff_key($flatIncoming, $flatCurrent);
        $removed = array_diff_key($flatCurrent, $flatIncoming);

        $changed = [];

        foreach ($flatIncoming as $key => $value) {
            if (isset($flatCurrent[$key]) && $flatCurrent[$key] !== $value) {
                $changed[$key] = ['old' => $flatCurrent[$key], 'new' => $value];
            }
        }

        return [
            'added' => $added,
            'changed' => $changed,
            'removed' => $removed,
        ];
    }

    /**
     * Flatten a nested array to dot-notation keys.
     */
    private static function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $result = array_merge($result, self::flattenArray($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
