<?php

namespace App\Modules\Core\Members\Http;

use App\Core\Documents\ReadModels\DocumentRequestQueueReadModel;
use App\Modules\Core\Members\UseCases\BatchRequestByRoleUseCase;
use App\Modules\Core\Members\UseCases\RequestDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-192: Document request endpoints (single + batch + queue).
 *
 * Controller is passive — delegates writes to UseCases, reads to ReadModel.
 */
class DocumentRequestController
{
    public function store(Request $request, RequestDocumentUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'document_type_code' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');

        $docRequest = $useCase->execute(
            $company->id,
            $validated['user_id'],
            $validated['document_type_code'],
        );

        return response()->json([
            'message' => 'Document requested.',
            'document_request' => $docRequest,
        ], 201);
    }

    public function batchByRole(Request $request, BatchRequestByRoleUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'company_role_id' => ['required', 'integer'],
            'document_type_code' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(
            $company->id,
            $validated['company_role_id'],
            $validated['document_type_code'],
        );

        return response()->json([
            'message' => "Batch complete: {$result['created']} created, {$result['skipped']} skipped.",
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'queue' => DocumentRequestQueueReadModel::forCompany($company->id),
        ]);
    }
}
