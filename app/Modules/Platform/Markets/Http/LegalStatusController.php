<?php

namespace App\Modules\Platform\Markets\Http;

use App\Core\Markets\LegalStatus;
use App\Core\Markets\Market;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalStatusController
{
    public function store(Request $request, string $marketKey): JsonResponse
    {
        Market::where('key', $marketKey)->firstOrFail();

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'vat_rate' => ['numeric', 'min:0', 'max:100'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        // Check uniqueness within market
        if (LegalStatus::where('market_key', $marketKey)->where('key', $validated['key'])->exists()) {
            return response()->json(['message' => 'Legal status key already exists for this market.'], 422);
        }

        // If setting as default, unset previous default for this market
        if (!empty($validated['is_default'])) {
            LegalStatus::where('market_key', $marketKey)->where('is_default', true)->update(['is_default' => false]);
        }

        $status = LegalStatus::create(array_merge($validated, ['market_key' => $marketKey]));

        return response()->json([
            'message' => 'Legal status created.',
            'legal_status' => $status,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $status = LegalStatus::findOrFail($id);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'vat_rate' => ['numeric', 'min:0', 'max:100'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        // Check uniqueness within market (exclude self)
        if (LegalStatus::where('market_key', $status->market_key)
            ->where('key', $validated['key'])
            ->where('id', '!=', $id)
            ->exists()) {
            return response()->json(['message' => 'Legal status key already exists for this market.'], 422);
        }

        // If setting as default, unset previous default for this market
        if (!empty($validated['is_default'])) {
            LegalStatus::where('market_key', $status->market_key)
                ->where('is_default', true)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $status->update($validated);

        return response()->json([
            'message' => 'Legal status updated.',
            'legal_status' => $status,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $status = LegalStatus::findOrFail($id);

        $status->delete();

        return response()->json([
            'message' => 'Legal status deleted.',
        ]);
    }

    public function reorder(Request $request, string $marketKey): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:legal_statuses,id'],
        ]);

        foreach ($validated['ids'] as $index => $id) {
            LegalStatus::where('id', $id)
                ->where('market_key', $marketKey)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Legal statuses reordered.']);
    }
}
