<?php

namespace App\Modules\Platform\Translations\Http;

use App\Core\Markets\TranslationBundle;
use App\Core\Markets\TranslationMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranslationMatrixController
{
    public function namespaces(): JsonResponse
    {
        // Get namespaces from static JSON (en.json is the reference locale)
        $path = resource_path('locales/en.json');
        if (!file_exists($path)) {
            $path = resource_path('js/plugins/i18n/locales/en.json');
        }
        $staticNamespaces = [];

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true) ?? [];

            foreach ($data as $key => $value) {
                // Top-level keys with array values are namespaces (skip $vuetify, flat strings)
                if (is_array($value) && !str_starts_with($key, '$')) {
                    $staticNamespaces[] = $key;
                }
            }
        }

        // Also include any additional namespaces from DB bundles
        $dbNamespaces = TranslationBundle::distinct()->pluck('namespace')->toArray();

        $all = array_unique(array_merge($staticNamespaces, $dbNamespaces));
        sort($all);

        return response()->json($all);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section' => ['required', 'string', 'max:100'],
            'locales' => ['required', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $locales = array_filter(explode(',', $validated['locales']));

        if (empty($locales)) {
            return response()->json(['message' => 'At least one locale is required.'], 422);
        }

        $result = TranslationMatrixService::buildMatrix(
            section: $validated['section'],
            locales: $locales,
            q: $validated['q'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 50),
        );

        return response()->json($result);
    }

    public function stats(): JsonResponse
    {
        $bundles = TranslationBundle::all(['locale', 'translations']);

        $locales = [];

        foreach ($bundles as $bundle) {
            if (!isset($locales[$bundle->locale])) {
                $locales[$bundle->locale] = ['bundles' => 0, 'keys' => 0];
            }

            $locales[$bundle->locale]['bundles']++;
            $locales[$bundle->locale]['keys'] += count($bundle->translations ?? []);
        }

        return response()->json(['locales' => $locales]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section' => ['required', 'string', 'max:100'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string', 'max:10'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.key' => ['required', 'string', 'max:500'],
            'rows.*.values' => ['required', 'array'],
        ]);

        $count = TranslationMatrixService::applyMatrix(
            section: $validated['section'],
            locales: $validated['locales'],
            rows: $validated['rows'],
        );

        return response()->json([
            'message' => "Matrix saved ({$count} keys updated).",
            'updated_count' => $count,
        ]);
    }
}
