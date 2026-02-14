<?php

namespace App\Company\Http\Controllers;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyFieldDefinitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $customDefinitions = FieldDefinition::where('company_id', $company->id)
            ->orderBy('default_order')
            ->get();

        // Compute used_count per definition (single query, no N+1)
        $defIds = $customDefinitions->pluck('id')->toArray();
        $usedCounts = [];

        if (!empty($defIds)) {
            $usedCounts = FieldValue::whereIn('field_definition_id', $defIds)
                ->groupBy('field_definition_id')
                ->selectRaw('field_definition_id, COUNT(*) as count')
                ->pluck('count', 'field_definition_id')
                ->toArray();
        }

        $customDefinitions->each(function ($def) use ($usedCounts) {
            $def->used_count = $usedCounts[$def->id] ?? 0;
        });

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
            'type' => ['required', Rule::in(FieldDefinition::COMPANY_TYPES)],
            'validation_rules' => ['sometimes', 'array'],
            'options' => ['required_if:type,select', 'array', 'min:1'],
            'options.*' => ['string', 'distinct', 'min:1'],
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

        $rules = [
            'label' => ['sometimes', 'string', 'max:100'],
            'validation_rules' => ['sometimes', 'nullable', 'array'],
            'options' => ['sometimes', 'nullable', 'array'],
            'default_order' => ['sometimes', 'integer', 'min:0'],
        ];

        if ($definition->type === FieldDefinition::TYPE_SELECT) {
            $rules['options'] = ['sometimes', 'array', 'min:1'];
            $rules['options.*'] = ['string', 'distinct', 'min:1'];
        }

        $validated = $request->validate($rules);

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

        $usedCount = FieldValue::where('field_definition_id', $definition->id)->count();

        // Cascade delete: values + activations + definition
        DB::transaction(function () use ($definition) {
            FieldValue::where('field_definition_id', $definition->id)->delete();
            FieldActivation::where('field_definition_id', $definition->id)->delete();
            $definition->delete();
        });

        return response()->json([
            'message' => 'Custom field deleted.',
            'deleted_values' => $usedCount,
        ]);
    }
}
