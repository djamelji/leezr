<?php

namespace App\Core\Fields;

use Illuminate\Database\Eloquent\Model;

class FieldResolverService
{
    /**
     * Resolve dynamic fields for a model.
     *
     * Executes exactly 3 queries (no N+1):
     *  1. All definitions for the scope
     *  2. All activations (batch by definition IDs)
     *  3. All values for the model (batch by definition IDs)
     *
     * @return array<int, array{code: string, label: string, type: string, options: array|null, required: bool, value: mixed, order: int}>
     */
    public static function resolve(Model $model, string $scope, ?int $companyId = null): array
    {
        // Query 1: all definitions for this scope (platform + current company)
        $definitions = FieldDefinition::where('scope', $scope)
            ->where(fn ($q) => $q->whereNull('company_id')
                ->when($companyId, fn ($q2) => $q2->orWhere('company_id', $companyId)))
            ->get()
            ->keyBy('id');

        if ($definitions->isEmpty()) {
            return [];
        }

        $defIds = $definitions->keys();

        // Query 2: activations in batch
        $activationQuery = FieldActivation::whereIn('field_definition_id', $defIds)
            ->where('enabled', true);

        if ($scope === FieldDefinition::SCOPE_PLATFORM_USER) {
            $activationQuery->whereNull('company_id');
        } else {
            $activationQuery->where('company_id', $companyId);
        }

        $activations = $activationQuery->get()->keyBy('field_definition_id');

        if ($activations->isEmpty()) {
            return [];
        }

        // Query 3: values in batch
        $values = FieldValue::where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->whereIn('field_definition_id', $activations->keys())
            ->get()
            ->keyBy('field_definition_id');

        // Assemble in memory
        $result = [];

        foreach ($activations as $defId => $activation) {
            $definition = $definitions->get($defId);
            if (!$definition) {
                continue;
            }

            $fieldValue = $values->get($defId);
            $rawValue = $fieldValue?->value;

            $isRequired = $activation->required_override
                || ($definition->validation_rules['required'] ?? false);

            $result[] = [
                'code' => $definition->code,
                'label' => $definition->label,
                'type' => $definition->type,
                'options' => $definition->options,
                'required' => $isRequired,
                'value' => $rawValue,
                'order' => $activation->order,
            ];
        }

        usort($result, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $result;
    }
}
