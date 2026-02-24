<?php

namespace App\Core\Markets;

/**
 * Merges translations with full fallback chain:
 *   market overrides > DB bundles > static JSON locale > static JSON en
 *
 * ADR-104: International Market Engine.
 */
class TranslationRepository
{
    /**
     * Returns merged translations for a locale, optionally filtered by namespace.
     *
     * Fallback chain (4 layers, merged bottom-up):
     * 1. English static JSON (base fallback, if locale != 'en')
     * 2. Requested locale static JSON
     * 3. DB bundles for requested locale
     * 4. Market overrides (if marketKey provided)
     *
     * @return array Keyed by namespace when no specific namespace, or flat translations when namespace given.
     */
    public static function bundle(string $locale, ?string $namespace = null, ?string $marketKey = null): array
    {
        // Layer 1: English fallback (if requested locale is not English)
        $result = [];

        if ($locale !== 'en') {
            $enStatic = self::staticJsonFallback('en', $namespace);
            $enDb = self::dbBundles('en', $namespace);
            $result = array_replace_recursive($enStatic, $enDb);
        }

        // Layer 2: Static JSON for requested locale
        $localeStatic = self::staticJsonFallback($locale, $namespace);
        $result = array_replace_recursive($result, $localeStatic);

        // Layer 3: DB bundles for requested locale
        $localeDb = self::dbBundles($locale, $namespace);
        $result = array_replace_recursive($result, $localeDb);

        // Layer 4: Market overrides
        if ($marketKey) {
            $overrides = TranslationOverride::where('market_key', $marketKey)
                ->where('locale', $locale)
                ->when($namespace, fn ($q) => $q->where('namespace', $namespace))
                ->get();

            foreach ($overrides as $override) {
                if ($namespace) {
                    data_set($result, $override->key, $override->value);
                } else {
                    data_set($result, "{$override->namespace}.{$override->key}", $override->value);
                }
            }
        }

        return $result;
    }

    /**
     * Fetch DB bundles for a locale, optionally filtered by namespace.
     * Returns keyed by namespace, or flat if namespace given.
     */
    private static function dbBundles(string $locale, ?string $namespace): array
    {
        $query = TranslationBundle::where('locale', $locale);

        if ($namespace) {
            $query->where('namespace', $namespace);
        }

        $result = [];

        foreach ($query->get() as $bundle) {
            if ($namespace) {
                $result = $bundle->translations;
            } else {
                $result[$bundle->namespace] = $bundle->translations;
            }
        }

        return $result;
    }

    /**
     * Read static JSON locale file as fallback.
     * Returns keyed by namespace, or just the namespace content if namespace given.
     */
    private static function staticJsonFallback(string $locale, ?string $namespace): array
    {
        $path = resource_path("js/plugins/i18n/locales/{$locale}.json");

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true) ?? [];

        return $namespace ? ($data[$namespace] ?? []) : $data;
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
