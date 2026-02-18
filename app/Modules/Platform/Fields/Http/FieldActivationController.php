<?php

namespace App\Modules\Platform\Fields\Http;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldActivationController
{
    private const MAX_ACTIVATIONS_PER_SCOPE = 50;

    public function index(): JsonResponse
    {
        $activations = FieldActivation::whereNull('company_id')
            ->with('definition')
            ->orderBy('order')
            ->get();

        return response()->json([
            'field_activations' => $activations,
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'field_definition_id' => ['required', 'exists:field_definitions,id'],
            'enabled' => ['required', 'boolean'],
            'required_override' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $definition = FieldDefinition::findOrFail($validated['field_definition_id']);

        if ($definition->scope !== FieldDefinition::SCOPE_PLATFORM_USER) {
            return response()->json([
                'message' => 'This field definition is not platform_user scope.',
            ], 422);
        }

        // Max activations guard
        $existing = FieldActivation::whereNull('company_id')
            ->where('field_definition_id', '!=', $definition->id)
            ->where('enabled', true)
            ->count();

        if ($validated['enabled'] && $existing >= self::MAX_ACTIVATIONS_PER_SCOPE) {
            return response()->json([
                'message' => 'Maximum number of active fields reached (' . self::MAX_ACTIVATIONS_PER_SCOPE . ').',
            ], 422);
        }

        $activation = FieldActivation::updateOrCreate(
            [
                'company_id' => null,
                'field_definition_id' => $validated['field_definition_id'],
            ],
            [
                'enabled' => $validated['enabled'],
                'required_override' => $validated['required_override'] ?? false,
                'order' => $validated['order'] ?? 0,
            ],
        );

        $activation->load('definition');

        return response()->json([
            'message' => 'Field activation updated.',
            'field_activation' => $activation,
        ]);
    }
}
