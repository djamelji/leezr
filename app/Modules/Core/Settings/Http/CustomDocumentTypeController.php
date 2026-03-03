<?php

namespace App\Modules\Core\Settings\Http;

use App\Modules\Core\Settings\UseCases\ArchiveCustomDocumentTypeData;
use App\Modules\Core\Settings\UseCases\ArchiveCustomDocumentTypeUseCase;
use App\Modules\Core\Settings\UseCases\CreateCustomDocumentTypeData;
use App\Modules\Core\Settings\UseCases\CreateCustomDocumentTypeUseCase;
use App\Modules\Core\Settings\UseCases\DeleteCustomDocumentTypeData;
use App\Modules\Core\Settings\UseCases\DeleteCustomDocumentTypeUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-180: Custom document type CRUD controller.
 *
 * Maps HTTP to UseCases. Zero business logic.
 */
class CustomDocumentTypeController extends Controller
{
    public function store(Request $request, CreateCustomDocumentTypeUseCase $useCase): JsonResponse
    {
        $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'scope' => ['required', 'string', 'in:company,company_user'],
            'max_file_size_mb' => ['required', 'integer', 'min:1', 'max:50'],
            'accepted_types' => ['required', 'array', 'min:1'],
            'accepted_types.*' => ['string', 'in:pdf,jpg,jpeg,png,doc,docx'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'required' => ['sometimes', 'boolean'],
        ]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(new CreateCustomDocumentTypeData(
            actor: $request->user(),
            company: $company,
            label: $request->input('label'),
            scope: $request->input('scope'),
            maxFileSizeMb: (int) $request->input('max_file_size_mb'),
            acceptedTypes: $request->input('accepted_types'),
            order: (int) $request->input('order', 0),
            required: $request->boolean('required'),
        ));

        return response()->json([
            'message' => 'Custom document type created.',
            'document_type' => [
                'id' => $result->id,
                'code' => $result->code,
                'label' => $result->label,
                'scope' => $result->scope,
            ],
        ], 201);
    }

    public function archive(Request $request, string $code, ArchiveCustomDocumentTypeUseCase $useCase): JsonResponse
    {
        $company = $request->attributes->get('company');

        $useCase->execute(new ArchiveCustomDocumentTypeData(
            actor: $request->user(),
            company: $company,
            code: $code,
        ));

        return response()->json(['message' => 'Document type archived.']);
    }

    public function destroy(Request $request, string $code, DeleteCustomDocumentTypeUseCase $useCase): JsonResponse
    {
        $company = $request->attributes->get('company');

        $useCase->execute(new DeleteCustomDocumentTypeData(
            actor: $request->user(),
            company: $company,
            code: $code,
        ));

        return response()->json(['message' => 'Document type deleted.']);
    }
}
