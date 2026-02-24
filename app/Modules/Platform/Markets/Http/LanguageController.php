<?php

namespace App\Modules\Platform\Markets\Http;

use App\Core\Markets\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LanguageController
{
    public function index(): JsonResponse
    {
        $languages = Language::withCount('markets')
            ->orderBy('sort_order')
            ->get();

        return response()->json($languages);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', 'unique:languages,key', 'regex:/^[a-z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        if (!empty($validated['is_default'])) {
            Language::where('is_default', true)->update(['is_default' => false]);
        }

        $language = Language::create($validated);

        return response()->json([
            'message' => 'Language created.',
            'language' => $language,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:10', "unique:languages,key,{$language->id}", 'regex:/^[a-z]{2,10}$/'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $language->update($validated);

        return response()->json([
            'message' => 'Language updated.',
            'language' => $language,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        $marketCount = $language->markets()->count();

        if ($marketCount > 0) {
            return response()->json([
                'message' => "Cannot delete: {$marketCount} markets are using this language.",
                'markets_count' => $marketCount,
            ], 422);
        }

        $language->delete();

        return response()->json(['message' => 'Language deleted.']);
    }

    public function toggleActive(int $id): JsonResponse
    {
        $language = Language::findOrFail($id);

        $language->update(['is_active' => !$language->is_active]);

        return response()->json([
            'message' => $language->is_active ? 'Language activated.' : 'Language deactivated.',
            'language' => $language,
        ]);
    }

    // ─── Import / Export ────────────────────────────────

    public function export(): JsonResponse
    {
        $languages = Language::orderBy('sort_order')->get();

        return response()->json($languages);
    }

    public function importApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:json', 'max:5120'],
        ]);

        $content = file_get_contents($validated['file']->getRealPath());
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return response()->json(['message' => 'Invalid JSON file.'], 422);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($data, &$created, &$updated) {
            foreach ($data as $lang) {
                if (empty($lang['key']) || empty($lang['name'])) {
                    continue;
                }

                $exists = Language::where('key', $lang['key'])->exists();

                Language::updateOrCreate(
                    ['key' => $lang['key']],
                    [
                        'name' => $lang['name'],
                        'native_name' => $lang['native_name'] ?? $lang['name'],
                        'sort_order' => $lang['sort_order'] ?? 0,
                        'is_active' => $lang['is_active'] ?? true,
                        'is_default' => $lang['is_default'] ?? false,
                    ],
                );

                $exists ? $updated++ : $created++;
            }
        });

        return response()->json([
            'message' => "Import applied: {$created} created, {$updated} updated.",
            'created' => $created,
            'updated' => $updated,
        ]);
    }
}
