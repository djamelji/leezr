<?php

namespace App\Core\Fields;

use App\Company\RBAC\CompanyRole;
use Illuminate\Database\Eloquent\Model;

class FieldResolverService
{
    /**
     * Codes of sensitive fields whose values are partially masked
     * unless the viewer has the members.sensitive_read permission.
     */
    public const SENSITIVE_CODES = ['social_security_number', 'iban'];

    /**
     * Resolve dynamic fields for a model.
     *
     * Executes exactly 3 queries (no N+1), +1 optional query when $roleKey is provided:
     *  1. All definitions for the scope
     *  2. All activations (batch by definition IDs)
     *  3. All values for the model (batch by definition IDs)
     *  4. (optional) CompanyRole for field_config override
     *
     * When $roleKey is provided and the role has a non-null field_config:
     *   - Fields IN field_config get their visible/required/order/group overrides applied
     *   - Fields NOT in field_config remain visible with their activation defaults
     *   - field_config is an OVERRIDE LAYER, not a whitelist
     *
     * When $canReadSensitive is false, sensitive field values are partially masked (****1234).
     *
     * @return array<int, array{code: string, label: string, type: string, options: array|null, required: bool, value: mixed, order: int, group?: string|null, sensitive?: bool}>
     */
    public static function resolve(Model $model, string $scope, ?int $companyId = null, ?string $roleKey = null, bool $canReadSensitive = true, ?string $marketKey = null, ?string $category = null): array
    {
        // Query 1: all definitions for this scope (platform + current company)
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

        // Assemble in memory
        $result = [];

        foreach ($activations as $defId => $activation) {
            $definition = $definitions->get($defId);
            if (!$definition) {
                continue;
            }

            $fieldValue = $values->get($defId);
            $rawValue = $fieldValue?->value;

            $mandatory = MandatoryContext::isMandatory($definition, $mandatoryContext, $roleRequiredTags);

            $isRequired = $activation->required_override
                || ($definition->validation_rules['required'] ?? false)
                || $mandatory;

            $isSensitive = $definition->validation_rules['sensitive'] ?? false;

            $result[] = [
                'code' => $definition->code,
                'label' => $definition->label,
                'type' => $definition->type,
                'options' => $definition->options,
                'required' => $isRequired,
                'mandatory' => $mandatory,
                'value' => $rawValue,
                'order' => $activation->order,
                'sensitive' => $isSensitive,
                'category' => $definition->validation_rules['category'] ?? FieldDefinition::CATEGORY_BASE,
            ];
        }

        usort($result, fn ($a, $b) => $a['order'] <=> $b['order']);

        // Apply role-based field_config override layer (ADR-164)
        if ($role !== null && $role->field_config !== null) {
            $configByCode = collect($role->field_config)->keyBy('code');

            $result = collect($result)
                ->map(function ($field) use ($configByCode) {
                    $config = $configByCode->get($field['code']);

                    if ($config) {
                        // Field has an override entry — apply it
                        if (($config['visible'] ?? true) === false) {
                            return null; // Hidden by role config
                        }
                        // ADR-169: mandatory fields cannot be downgraded by role config
                        $field['required'] = $field['mandatory'] || ($config['required'] ?? $field['required']);
                        $field['order'] = $config['order'] ?? $field['order'];
                        $field['group'] = $config['group'] ?? null;
                    } else {
                        // Field NOT in config — keep visible with defaults
                        $field['group'] = null;
                    }

                    return $field;
                })
                ->filter()
                ->sortBy('order')
                ->values()
                ->toArray();
        }

        // Apply sensitive field masking
        if (!$canReadSensitive) {
            $result = array_map(function ($field) {
                if (!empty($field['sensitive']) && $field['value'] !== null) {
                    $field['value'] = static::mask($field['value']);
                    $field['masked'] = true;
                }

                return $field;
            }, $result);
        }

        return $result;
    }

    /**
     * Partially mask a sensitive value: show last 4 characters only.
     */
    private static function mask(mixed $value): string
    {
        $str = (string) $value;
        $len = mb_strlen($str);

        if ($len <= 4) {
            return '****';
        }

        return str_repeat('*', $len - 4) . mb_substr($str, -4);
    }
}
