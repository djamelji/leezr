<?php

namespace App\Modules\Platform\Documents\Http;

use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\ReadModels\PlatformDocTypeCatalogReadModel;
use App\Modules\Platform\Documents\UseCases\ArchiveSystemDocumentTypeData;
use App\Modules\Platform\Documents\UseCases\ArchiveSystemDocumentTypeUseCase;
use App\Modules\Platform\Documents\UseCases\CreateSystemDocumentTypeData;
use App\Modules\Platform\Documents\UseCases\CreateSystemDocumentTypeUseCase;
use App\Modules\Platform\Documents\UseCases\RestoreSystemDocumentTypeData;
use App\Modules\Platform\Documents\UseCases\RestoreSystemDocumentTypeUseCase;
use App\Modules\Platform\Documents\UseCases\UpdateSystemDocumentTypeData;
use App\Modules\Platform\Documents\UseCases\UpdateSystemDocumentTypeUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-182: Passive controller for platform document type catalog.
 * All logic delegated to ReadModel and UseCases.
 */
class DocumentTypeCatalogController
{
    public function index(): JsonResponse
    {
        return response()->json(PlatformDocTypeCatalogReadModel::index());
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(PlatformDocTypeCatalogReadModel::show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = CreateSystemDocumentTypeData::from($request);
        $result = (new CreateSystemDocumentTypeUseCase)->execute($data);

        return response()->json($result, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = UpdateSystemDocumentTypeData::from($request, $id);
        $result = (new UpdateSystemDocumentTypeUseCase)->execute($data);

        return response()->json($result);
    }

    public function archive(int $id): JsonResponse
    {
        (new ArchiveSystemDocumentTypeUseCase)->execute(
            new ArchiveSystemDocumentTypeData($id)
        );

        return response()->json(['message' => 'Document type archived.']);
    }

    public function restore(int $id): JsonResponse
    {
        (new RestoreSystemDocumentTypeUseCase)->execute(
            new RestoreSystemDocumentTypeData($id)
        );

        return response()->json(['message' => 'Document type restored.']);
    }

    public function sync(): JsonResponse
    {
        DocumentTypeCatalog::sync();

        return response()->json(PlatformDocTypeCatalogReadModel::index());
    }
}
