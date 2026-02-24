<?php

namespace App\Modules\Platform\Translations\Http;

use App\Core\Markets\TranslationOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverrideController
{
    public function index(string $marketKey, string $locale): JsonResponse
    {
        $overrides = TranslationOverride::where('market_key', $marketKey)
            ->where('locale', $locale)
            ->orderBy('namespace')
            ->orderBy('key')
            ->get();

        return response()->json($overrides);
    }

    public function upsert(Request $request, string $marketKey): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'overrides' => ['required', 'array', 'min:1'],
            'overrides.*.namespace' => ['required', 'string', 'max:100'],
            'overrides.*.key' => ['required', 'string', 'max:500'],
            'overrides.*.value' => ['required', 'string'],
        ]);

        $count = 0;

        foreach ($validated['overrides'] as $item) {
            TranslationOverride::updateOrCreate(
                [
                    'market_key' => $marketKey,
                    'locale' => $validated['locale'],
                    'namespace' => $item['namespace'],
                    'key' => $item['key'],
                ],
                ['value' => $item['value']],
            );
            $count++;
        }

        return response()->json([
            'message' => "Saved {$count} overrides.",
            'count' => $count,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $override = TranslationOverride::findOrFail($id);
        $override->delete();

        return response()->json([
            'message' => 'Override removed.',
        ]);
    }
}
