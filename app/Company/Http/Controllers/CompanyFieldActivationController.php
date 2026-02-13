<?php

namespace App\Company\Http\Controllers;

use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CompanyFieldActivationController extends Controller
{
    private const MAX_ACTIVATIONS_PER_SCOPE = 50;

    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $activations = FieldActivation::where('company_id', $company->id)
            ->with('definition')
            ->orderBy('order')
            ->get();

        $activatedIds = $activations->pluck('field_definition_id')->toArray();

        $available = FieldDefinition::whereIn('scope', [
            FieldDefinition::SCOPE_COMPANY,
            FieldDefinition::SCOPE_COMPANY_USER,
        ])
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->whereNotIn('id', $activatedIds)
            ->get();

        // Compute used_count per field_definition_id (single query, no N+1)
        $memberUserIds = $company->memberships()->pluck('user_id')->toArray();

        $usedCounts = [];
        if (!empty($memberUserIds) && !empty($activatedIds)) {
            $usedCounts = FieldValue::where(function ($q) use ($memberUserIds, $company) {
                $q->where(function ($q2) use ($memberUserIds) {
                    $q2->where('model_type', 'user')
                        ->whereIn('model_id', $memberUserIds);
                })->orWhere(function ($q2) use ($company) {
                    $q2->where('model_type', 'company')
                        ->where('model_id', $company->id);
                });
            })
                ->whereIn('field_definition_id', $activatedIds)
                ->whereNotNull('value')
                ->groupBy('field_definition_id')
                ->selectRaw('field_definition_id, COUNT(*) as count')
                ->pluck('count', 'field_definition_id')
                ->toArray();
        }

        // Attach used_count to each activation
        $activations->each(function ($activation) use ($usedCounts) {
            $activation->used_count = $usedCounts[$activation->field_definition_id] ?? 0;
        });

        return response()->json([
            'field_activations' => $activations,
            'available_definitions' => $available,
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'field_definition_id' => ['required', 'exists:field_definitions,id'],
            'enabled' => ['required', 'boolean'],
            'required_override' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $definition = FieldDefinition::findOrFail($validated['field_definition_id']);

        if (!in_array($definition->scope, [FieldDefinition::SCOPE_COMPANY, FieldDefinition::SCOPE_COMPANY_USER])) {
            return response()->json([
                'message' => 'This field definition is not applicable to company scope.',
            ], 422);
        }

        // Cross-tenant guard: cannot activate a custom field owned by another company
        if ($definition->company_id !== null && $definition->company_id !== $company->id) {
            return response()->json([
                'message' => 'This field definition does not belong to your company.',
            ], 403);
        }

        // Max activations guard per scope
        $existing = FieldActivation::where('company_id', $company->id)
            ->where('field_definition_id', '!=', $definition->id)
            ->where('enabled', true)
            ->whereHas('definition', fn ($q) => $q->where('scope', $definition->scope))
            ->count();

        if ($validated['enabled'] && $existing >= self::MAX_ACTIVATIONS_PER_SCOPE) {
            return response()->json([
                'message' => 'Maximum number of active fields reached (' . self::MAX_ACTIVATIONS_PER_SCOPE . ') for scope ' . $definition->scope . '.',
            ], 422);
        }

        $activation = FieldActivation::updateOrCreate(
            [
                'company_id' => $company->id,
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
