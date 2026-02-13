<?php

namespace App\Company\Http\Controllers;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class CompanyFieldDefinitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $customDefinitions = FieldDefinition::where('company_id', $company->id)
            ->orderBy('default_order')
            ->get();

        return response()->json([
            'custom_definitions' => $customDefinitions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        // Gate: jobdomain must allow custom fields
        if (!$company->jobdomain?->allow_custom_fields) {
            return response()->json([
                'message' => 'Your industry profile does not allow custom field creation.',
            ], 403);
        }

        // Limit guard
        $currentCount = FieldDefinition::where('company_id', $company->id)->count();

        if ($currentCount >= FieldDefinition::MAX_CUSTOM_FIELDS_PER_COMPANY) {
            return response()->json([
                'message' => 'Maximum number of custom fields reached (' . FieldDefinition::MAX_CUSTOM_FIELDS_PER_COMPANY . ').',
            ], 422);
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z][a-z0-9_]*$/',
            ],
            'label' => ['required', 'string', 'max:100'],
            'scope' => ['required', Rule::in(FieldDefinition::COMPANY_SCOPES)],
            'type' => ['required', Rule::in(FieldDefinition::TYPES)],
            'validation_rules' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        // Uniqueness within company
        $exists = FieldDefinition::where('company_id', $company->id)
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A custom field with this code already exists.',
            ], 422);
        }

        $definition = FieldDefinition::create([
            'company_id' => $company->id,
            'code' => $validated['code'],
            'scope' => $validated['scope'],
            'label' => $validated['label'],
            'type' => $validated['type'],
            'validation_rules' => $validated['validation_rules'] ?? null,
            'options' => $validated['options'] ?? null,
            'is_system' => false,
            'created_by_platform' => false,
            'default_order' => $validated['default_order'] ?? 0,
        ]);

        // Auto-activate the field for the company
        FieldActivation::create([
            'company_id' => $company->id,
            'field_definition_id' => $definition->id,
            'enabled' => true,
            'required_override' => false,
            'order' => $validated['default_order'] ?? 0,
        ]);

        $definition->load('activations');

        return response()->json([
            'message' => 'Custom field created and activated.',
            'field_definition' => $definition,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        // Gate: jobdomain must allow custom fields
        if (!$company->jobdomain?->allow_custom_fields) {
            return response()->json([
                'message' => 'Your industry profile does not allow custom field management.',
            ], 403);
        }

        $definition = FieldDefinition::where('company_id', $company->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'validation_rules' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $definition->update($validated);

        return response()->json([
            'message' => 'Custom field updated.',
            'field_definition' => $definition,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        // Gate: jobdomain must allow custom fields
        if (!$company->jobdomain?->allow_custom_fields) {
            return response()->json([
                'message' => 'Your industry profile does not allow custom field management.',
            ], 403);
        }

        $definition = FieldDefinition::where('company_id', $company->id)
            ->findOrFail($id);

        if ($definition->is_system) {
            return response()->json([
                'message' => 'System fields cannot be deleted.',
            ], 403);
        }

        // Check if field has values
        $usedCount = FieldValue::where('field_definition_id', $definition->id)->count();

        if ($usedCount > 0) {
            return response()->json([
                'message' => "Cannot delete: this field is used by {$usedCount} record(s). You can disable it instead.",
            ], 422);
        }

        // Delete activations first, then definition
        FieldActivation::where('field_definition_id', $definition->id)->delete();
        $definition->delete();

        return response()->json([
            'message' => 'Custom field deleted.',
        ]);
    }
}
