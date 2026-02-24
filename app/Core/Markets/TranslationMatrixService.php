<?php

namespace App\Core\Markets;

use Illuminate\Support\Facades\DB;

/**
 * Core service for the Translation Matrix Editor.
 * Builds a multi-locale side-by-side grid from translation_bundles.
 *
 * ADR-104: International Market Engine — Translation Governance.
 */
class TranslationMatrixService
{
    /**
     * Build a matrix of translation keys × locales for a given section (namespace).
     *
     * @return array{section: string, locales: array, rows: array, pagination: array}
     */
    public static function buildMatrix(string $section, array $locales, ?string $q, int $page, int $perPage): array
    {
        // Fetch DB bundles for the given section + locales
        $bundles = TranslationBundle::where('namespace', $section)
            ->whereIn('locale', $locales)
            ->get()
            ->keyBy('locale');

        // Flatten each locale's translations to dot-notation
        // Merge: static JSON (base) ← DB bundles (overrides)
        $flatByLocale = [];
        foreach ($locales as $locale) {
            $staticData = self::staticJsonSection($locale, $section);
            $dbData = $bundles->get($locale)?->translations ?? [];
            $merged = array_replace_recursive($staticData, $dbData);
            $flatByLocale[$locale] = self::flattenArray($merged);
        }

        // Build union of all keys across locales
        $allKeys = [];
        foreach ($flatByLocale as $flat) {
            foreach (array_keys($flat) as $key) {
                $allKeys[$key] = true;
            }
        }

        ksort($allKeys);
        $allKeys = array_keys($allKeys);

        // Apply search filter
        if ($q) {
            $qLower = mb_strtolower($q);

            $allKeys = array_values(array_filter($allKeys, function ($key) use ($qLower, $flatByLocale) {
                // Match on key name
                if (str_contains(mb_strtolower($key), $qLower)) {
                    return true;
                }

                // Match on any locale value
                foreach ($flatByLocale as $flat) {
                    if (isset($flat[$key]) && str_contains(mb_strtolower((string) $flat[$key]), $qLower)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        // Paginate
        $total = count($allKeys);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $pageKeys = array_slice($allKeys, $offset, $perPage);

        // Build rows
        $rows = [];
        foreach ($pageKeys as $key) {
            $values = [];
            foreach ($locales as $locale) {
                $values[$locale] = $flatByLocale[$locale][$key] ?? '';
            }
            $rows[] = ['key' => $key, 'values' => $values];
        }

        return [
            'section' => $section,
            'locales' => $locales,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * Bulk upsert translation keys from the matrix editor.
     * Empty string values remove the key (enables fallback).
     *
     * @return int Number of keys processed
     */
    public static function applyMatrix(string $section, array $locales, array $rows): int
    {
        $count = 0;

        DB::transaction(function () use ($section, $locales, $rows, &$count) {
            // Load current bundles for each locale
            $bundles = TranslationBundle::where('namespace', $section)
                ->whereIn('locale', $locales)
                ->get()
                ->keyBy('locale');

            foreach ($locales as $locale) {
                $bundle = $bundles->get($locale);
                $translations = $bundle?->translations ?? [];

                // Flatten to work with dot-notation keys
                $flat = self::flattenArray($translations);

                foreach ($rows as $row) {
                    $key = $row['key'] ?? null;
                    $value = $row['values'][$locale] ?? null;

                    if ($key === null) {
                        continue;
                    }

                    if ($value === '' || $value === null) {
                        // Empty = remove key (fallback applies)
                        unset($flat[$key]);
                    } else {
                        $flat[$key] = $value;
                        $count++;
                    }
                }

                // Rebuild nested array from flat
                $nested = self::unflattenArray($flat);

                TranslationBundle::updateOrCreate(
                    ['locale' => $locale, 'namespace' => $section],
                    ['translations' => $nested],
                );
            }
        });

        return $count;
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

    /**
     * Read a section (namespace) from the static JSON locale file.
     */
    private static function staticJsonSection(string $locale, string $section): array
    {
        $path = resource_path("locales/{$locale}.json");
        if (!file_exists($path)) {
            $path = resource_path("js/plugins/i18n/locales/{$locale}.json");
        }

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true) ?? [];

        return is_array($data[$section] ?? null) ? $data[$section] : [];
    }

    /**
     * Rebuild a nested array from dot-notation keys.
     */
    private static function unflattenArray(array $flat): array
    {
        $result = [];

        foreach ($flat as $key => $value) {
            data_set($result, $key, $value);
        }

        return $result;
    }
}
