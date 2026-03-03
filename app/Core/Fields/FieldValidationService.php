<?php

namespace App\Core\Fields;

use App\Company\RBAC\CompanyRole;

class FieldValidationService
{
    /**
     * Generate Laravel validation rules for dynamic fields in a given scope/company.
     *
     * When $roleKey is provided and the role has field_config:
     *   - Fields IN field_config with visible=false are excluded from rules
     *   - Fields IN field_config get their required override applied
     *   - Fields NOT in field_config remain with their activation defaults
     *
     * Returns a separate array — never mutates base rules.
     *
     * @return array<string, array<string>>
     */
    public static function rules(string $scope, ?int $companyId = null, ?string $roleKey = null, ?string $marketKey = null, ?string $category = null): array
    {
        $definitions = FieldDefinition::where('scope', $scope)
            ->where(fn ($q) => $q->whereNull('company_id')
                ->when($companyId, fn ($q2) => $q2->orWhere('company_id', $companyId)))
            ->get()
            ->keyBy('id');

        // ADR-165: filter out definitions not applicable to the company's market
        if ($marketKey) {
            $definitions = $definitions->filter(function ($def) use ($marketKey) {
                $markets = $def->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        // ADR-168: filter by category (base/hr/domain)
        if ($category !== null) {
            $definitions = $definitions->filter(function ($def) use ($category) {
                return ($def->validation_rules['category'] ?? FieldDefinition::CATEGORY_BASE) === $category;
            });
        }

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

        // ADR-168b: mandatory context (cached per-request, 0 extra queries on subsequent calls)
        $mandatoryContext = MandatoryContext::load($companyId);

        // ADR-170: Load role once (reused for both tag resolution + field_config overlay)
        $role = null;
        $roleRequiredTags = null;
        if ($roleKey !== null && $companyId !== null) {
            $role = CompanyRole::where('company_id', $companyId)
                ->where('key', $roleKey)
                ->first();
            $roleRequiredTags = $role?->required_tags;
        }

        $rules = ['dynamic_fields' => ['sometimes', 'array']];

        foreach ($activations as $activation) {
            $definition = $definitions->get($activation->field_definition_id);
            if (!$definition) {
                continue;
            }

            $fieldRules = ['sometimes'];

            $mandatory = MandatoryContext::isMandatory($definition, $mandatoryContext, $roleRequiredTags);

            $isRequired = $activation->required_override
                || ($definition->validation_rules['required'] ?? false)
                || $mandatory;

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

        // Apply role-based field_config override layer (ADR-164)
        if ($role !== null && $role->field_config !== null) {
            $configByCode = collect($role->field_config)->keyBy('code');
            $filteredRules = ['dynamic_fields' => ['sometimes', 'array']];

            foreach ($rules as $ruleKey => $ruleValue) {
                if ($ruleKey === 'dynamic_fields') {
                    continue;
                }

                // Extract code from "dynamic_fields.{code}"
                $code = str_replace('dynamic_fields.', '', $ruleKey);
                $config = $configByCode->get($code);

                if ($config && ($config['visible'] ?? true) === false) {
                    continue; // Hidden by role config — skip validation rule
                }

                if ($config && isset($config['required'])) {
                    // ADR-169: mandatory fields cannot be downgraded by role config
                    $definition = $definitions->first(fn ($d) => $d->code === $code);
                    $mandatory = $definition ? MandatoryContext::isMandatory($definition, $mandatoryContext, $roleRequiredTags) : false;
                    $effectiveRequired = $mandatory || $config['required'];

                    $ruleValue = array_map(function ($r) use ($effectiveRequired) {
                        if ($r === 'required' || $r === 'nullable') {
                            return $effectiveRequired ? 'required' : 'nullable';
                        }

                        return $r;
                    }, $ruleValue);
                }

                $filteredRules[$ruleKey] = $ruleValue;
            }

            return $filteredRules;
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
