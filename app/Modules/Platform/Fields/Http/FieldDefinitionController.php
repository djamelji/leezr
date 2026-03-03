<?php

namespace App\Modules\Platform\Fields\Http;

use App\Modules\Platform\Fields\ReadModels\PlatformFieldReadModel;
use App\Modules\Platform\Fields\UseCases\DeletePlatformFieldUseCase;
use App\Modules\Platform\Fields\UseCases\UpsertPlatformFieldUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FieldDefinitionController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'field_definitions' => PlatformFieldReadModel::catalog($request->input('scope')),
        ]);
    }

    public function store(Request $request, UpsertPlatformFieldUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'scope' => ['required', Rule::in(\App\Core\Fields\FieldDefinition::SCOPES)],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(\App\Core\Fields\FieldDefinition::TYPES)],
            'validation_rules' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $definition = $useCase->execute(null, $validated);

        return response()->json([
            'message' => 'Field definition created.',
            'field_definition' => $definition,
        ], 201);
    }

    public function update(Request $request, int $id, UpsertPlatformFieldUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'validation_rules' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $definition = $useCase->execute($id, $validated);

        return response()->json([
            'message' => 'Field definition updated.',
            'field_definition' => $definition,
        ]);
    }

    public function destroy(int $id, DeletePlatformFieldUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return response()->json([
            'message' => 'Field definition deleted.',
        ]);
    }
}
