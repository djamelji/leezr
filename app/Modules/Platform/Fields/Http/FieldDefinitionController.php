<?php

namespace App\Modules\Platform\Fields\Http;

use App\Core\Fields\FieldDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FieldDefinitionController
{
    public function index(Request $request): JsonResponse
    {
        $query = FieldDefinition::whereNull('company_id')
            ->orderBy('scope')
            ->orderBy('default_order');

        if ($scope = $request->input('scope')) {
            $query->where('scope', $scope);
        }

        return response()->json([
            'field_definitions' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'scope' => ['required', Rule::in(FieldDefinition::SCOPES)],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(FieldDefinition::TYPES)],
            'validation_rules' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        // Uniqueness among platform-owned definitions
        $exists = FieldDefinition::whereNull('company_id')
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A platform field definition with this code already exists.',
                'errors' => ['code' => ['The code has already been taken.']],
            ], 422);
        }

        $definition = FieldDefinition::create(array_merge($validated, [
            'company_id' => null,
            'is_system' => false,
            'created_by_platform' => true,
        ]));

        return response()->json([
            'message' => 'Field definition created.',
            'field_definition' => $definition,
        ], 201);
    }

    /**
     * Only label, validation_rules, options, default_order are mutable.
     * code, scope, type are immutable after creation.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $definition = FieldDefinition::whereNull('company_id')->findOrFail($id);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'validation_rules' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $definition->update($validated);

        return response()->json([
            'message' => 'Field definition updated.',
            'field_definition' => $definition,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $definition = FieldDefinition::whereNull('company_id')->findOrFail($id);

        if ($definition->is_system) {
            return response()->json([
                'message' => 'Cannot delete a system field.',
            ], 403);
        }

        $definition->delete();

        return response()->json([
            'message' => 'Field definition deleted.',
        ]);
    }
}
