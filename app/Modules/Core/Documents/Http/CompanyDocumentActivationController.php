<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\ReadModels\CompanyDocumentActivationReadModel;
use App\Modules\Core\Settings\UseCases\UpsertDocumentActivationData;
use App\Modules\Core\Settings\UseCases\UpsertDocumentActivationUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-175: Document activation catalog controller (passive).
 *
 * Maps HTTP to ReadModel/UseCase. Zero business logic.
 */
class CompanyDocumentActivationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            CompanyDocumentActivationReadModel::get($company),
        );
    }

    public function upsert(Request $request, string $code, UpsertDocumentActivationUseCase $useCase): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
            'required_override' => ['required', 'boolean'],
            'order' => ['required', 'integer', 'min:0'],
        ]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(new UpsertDocumentActivationData(
            actor: $request->user(),
            company: $company,
            documentCode: $code,
            enabled: $request->boolean('enabled'),
            requiredOverride: $request->boolean('required_override'),
            order: (int) $request->input('order'),
        ));

        return response()->json([
            'message' => 'Document activation updated.',
            'activation' => [
                'code' => $result->code,
                'enabled' => $result->enabled,
                'required_override' => $result->requiredOverride,
                'order' => $result->order,
            ],
        ]);
    }
}
