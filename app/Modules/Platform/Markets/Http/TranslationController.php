<?php

namespace App\Modules\Platform\Markets\Http;

use App\Core\Markets\TranslationBundle;
use App\Core\Markets\TranslationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TranslationController
{
    public function index(Request $request): JsonResponse
    {
        $query = TranslationBundle::query();

        if ($locale = $request->query('locale')) {
            $query->where('locale', $locale);
        }

        if ($namespace = $request->query('namespace')) {
            $query->where('namespace', $namespace);
        }

        $bundles = $query->orderBy('locale')
            ->orderBy('namespace')
            ->paginate($request->query('per_page', 25));

        // Add key count to each bundle
        $bundles->getCollection()->transform(function ($bundle) {
            $bundle->keys_count = count(self::flattenArray($bundle->translations));

            return $bundle;
        });

        return response()->json($bundles);
    }

    public function show(string $locale, string $namespace): JsonResponse
    {
        $bundle = TranslationBundle::where('locale', $locale)
            ->where('namespace', $namespace)
            ->firstOrFail();

        return response()->json($bundle);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $bundle = TranslationBundle::findOrFail($id);

        $validated = $request->validate([
            'translations' => ['required', 'array'],
        ]);

        $bundle->update(['translations' => $validated['translations']]);

        return response()->json([
            'message' => 'Translations updated.',
            'bundle' => $bundle,
        ]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'file' => ['required', 'file', 'mimes:json', 'max:5120'],
        ]);

        $content = file_get_contents($validated['file']->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return response()->json(['message' => 'Invalid JSON file.'], 422);
        }

        $locale = $validated['locale'];
        $diffs = [];

        foreach ($data as $namespace => $translations) {
            if (!is_array($translations)) {
                continue;
            }

            $diff = TranslationRepository::diff($locale, $namespace, $translations);

            if (!empty($diff['added']) || !empty($diff['changed']) || !empty($diff['removed'])) {
                $diffs[$namespace] = $diff;
            }
        }

        return response()->json([
            'locale' => $locale,
            'namespaces_affected' => count($diffs),
            'diffs' => $diffs,
        ]);
    }

    public function importApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'file' => ['required', 'file', 'mimes:json', 'max:5120'],
        ]);

        $content = file_get_contents($validated['file']->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return response()->json(['message' => 'Invalid JSON file.'], 422);
        }

        $locale = $validated['locale'];
        $count = 0;

        DB::transaction(function () use ($data, $locale, &$count) {
            foreach ($data as $namespace => $translations) {
                if (!is_array($translations)) {
                    continue;
                }

                TranslationBundle::updateOrCreate(
                    ['locale' => $locale, 'namespace' => $namespace],
                    ['translations' => $translations],
                );

                $count++;
            }
        });

        return response()->json([
            'message' => "Imported {$count} namespaces for locale '{$locale}'.",
            'count' => $count,
        ]);
    }

    public function export(string $locale): JsonResponse
    {
        $bundles = TranslationBundle::where('locale', $locale)
            ->orderBy('namespace')
            ->get();

        $result = [];

        foreach ($bundles as $bundle) {
            $result[$bundle->namespace] = $bundle->translations;
        }

        return response()->json($result);
    }

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
