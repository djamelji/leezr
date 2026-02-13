<?php

namespace App\Core\Fields;

class FieldValidationService
{
    /**
     * Generate Laravel validation rules for dynamic fields in a given scope/company.
     *
     * Returns a separate array — never mutates base rules.
     *
     * @return array<string, array<string>>
     */
    public static function rules(string $scope, ?int $companyId = null): array
    {
        $definitions = FieldDefinition::where('scope', $scope)
            ->where(fn ($q) => $q->whereNull('company_id')
                ->when($companyId, fn ($q2) => $q2->orWhere('company_id', $companyId)))
            ->get()
            ->keyBy('id');

        if ($definitions->isEmpty()) {
            return ['dynamic_fields' => ['sometimes', 'array']];
        }

        $activationQuery = FieldActivation::whereIn('field_definition_id', $definitions->keys())
            ->where('enabled', true);

        if ($scope === FieldDefinition::SCOPE_PLATFORM_USER) {
            $activationQuery->whereNull('company_id');
        } else {
            $activationQuery->where('company_id', $companyId);
        }

        $activations = $activationQuery->get();

        $rules = ['dynamic_fields' => ['sometimes', 'array']];

        foreach ($activations as $activation) {
            $definition = $definitions->get($activation->field_definition_id);
            if (!$definition) {
                continue;
            }

            $fieldRules = ['sometimes'];

            $isRequired = $activation->required_override
                || ($definition->validation_rules['required'] ?? false);

            $fieldRules[] = $isRequired ? 'required' : 'nullable';

            // Type-based rules with bounded sizes
            match ($definition->type) {
                FieldDefinition::TYPE_STRING => static::addStringRules($fieldRules, $definition),
                FieldDefinition::TYPE_NUMBER => $fieldRules[] = 'numeric',
                FieldDefinition::TYPE_DATE => $fieldRules[] = 'date',
                FieldDefinition::TYPE_BOOLEAN => $fieldRules[] = 'boolean',
                FieldDefinition::TYPE_SELECT => static::addSelectRules($fieldRules, $definition),
                FieldDefinition::TYPE_JSON => array_push($fieldRules, 'string', 'max:10000'),
            };

            $rules["dynamic_fields.{$definition->code}"] = $fieldRules;
        }

        return $rules;
    }

    private static function addStringRules(array &$rules, FieldDefinition $definition): void
    {
        $rules[] = 'string';
        $max = $definition->validation_rules['max'] ?? 1000;
        $rules[] = "max:{$max}";

        if ($min = $definition->validation_rules['min'] ?? null) {
            $rules[] = "min:{$min}";
        }
    }

    private static function addSelectRules(array &$rules, FieldDefinition $definition): void
    {
        $rules[] = 'string';
        if ($definition->options) {
            $rules[] = 'in:' . implode(',', $definition->options);
        }
    }
}
