<?php

namespace App\Modules\Platform\Jobdomains\Http;

use App\Modules\Platform\Jobdomains\ReadModels\PlatformJobdomainReadModel;
use App\Modules\Platform\Jobdomains\UseCases\DeleteJobdomainOverlayUseCase;
use App\Modules\Platform\Jobdomains\UseCases\UpsertJobdomainOverlayUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobdomainOverlayController
{
    public function index(string $jobdomainKey): JsonResponse
    {
        return response()->json([
            'overlays' => PlatformJobdomainReadModel::overlaysForJobdomain($jobdomainKey),
        ]);
    }

    public function upsert(
        Request $request,
        string $jobdomainKey,
        string $marketKey,
        UpsertJobdomainOverlayUseCase $useCase,
    ): JsonResponse {
        $validated = $request->validate([
            'override_modules' => ['nullable', 'array'],
            'override_modules.*' => ['string'],
            'override_fields' => ['nullable', 'array'],
            'override_fields.*.code' => ['required', 'string'],
            'override_fields.*.order' => ['sometimes', 'integer', 'min:0'],
            'override_documents' => ['nullable', 'array'],
            'override_documents.*.code' => ['required', 'string'],
            'override_documents.*.order' => ['sometimes', 'integer', 'min:0'],
            'override_roles' => ['nullable', 'array'],
            'remove_modules' => ['nullable', 'array'],
            'remove_modules.*' => ['string'],
            'remove_fields' => ['nullable', 'array'],
            'remove_fields.*' => ['string'],
            'remove_documents' => ['nullable', 'array'],
            'remove_documents.*' => ['string'],
            'remove_roles' => ['nullable', 'array'],
            'remove_roles.*' => ['string'],
        ]);

        $overlay = $useCase->execute($jobdomainKey, $marketKey, $validated);

        return response()->json([
            'message' => 'Overlay saved.',
            'overlay' => $overlay,
        ]);
    }

    public function destroy(
        string $jobdomainKey,
        string $marketKey,
        DeleteJobdomainOverlayUseCase $useCase,
    ): JsonResponse {
        $useCase->execute($jobdomainKey, $marketKey);

        return response()->json([
            'message' => 'Overlay deleted.',
        ]);
    }
}
