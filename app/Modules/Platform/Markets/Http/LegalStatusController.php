<?php

namespace App\Modules\Platform\Markets\Http;

use App\Modules\Platform\Markets\MarketModuleCrudService;
use App\Modules\Platform\Markets\UseCases\UpsertLegalStatusData;
use App\Modules\Platform\Markets\UseCases\UpsertLegalStatusUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalStatusController
{
    public function store(Request $request, string $marketKey, UpsertLegalStatusUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_vat_applicable' => ['required', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $status = $useCase->execute(UpsertLegalStatusData::fromValidated(null, $marketKey, $validated));

        return response()->json([
            'message' => 'Legal status created.',
            'legal_status' => $status,
        ], 201);
    }

    public function update(Request $request, int $id, UpsertLegalStatusUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_vat_applicable' => ['required', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $status = $useCase->execute(UpsertLegalStatusData::fromValidated($id, null, $validated));

        return response()->json([
            'message' => 'Legal status updated.',
            'legal_status' => $status,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        MarketModuleCrudService::deleteLegalStatus($id);

        return response()->json(['message' => 'Legal status deleted.']);
    }

    public function reorder(Request $request, string $marketKey): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:legal_statuses,id'],
        ]);

        MarketModuleCrudService::reorderLegalStatuses($marketKey, $validated['ids']);

        return response()->json(['message' => 'Legal statuses reordered.']);
    }
}
