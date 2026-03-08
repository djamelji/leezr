<?php

namespace App\Core\Fields;

use App\Core\Markets\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FieldWriteService
{
    /**
     * Upsert dynamic field values for a model.
     *
     * Only writes values for fields that have an active activation.
     * Cross-tenant prevention: when scope != platform_user, companyId is required
     * and activation must match.
     *
     * Partial updates: only provided codes are upserted, existing values for
     * non-provided codes are preserved.
     *
     * @param Model $model
     * @param array<string, mixed> $dynamicFields Keyed by field code => value
     * @param string $scope
     * @param int|null $companyId
     */
    public static function upsert(Model $model, array $dynamicFields, string $scope, ?int $companyId = null, ?string $marketKey = null, ?string $category = null): void
    {
        if (empty($dynamicFields)) {
            return;
        }

        // Resolve valid definitions for provided codes (platform + current company)
        $definitions = FieldDefinition::where('scope', $scope)
            ->where(fn ($q) => $q->whereNull('company_id')
                ->when($companyId, fn ($q2) => $q2->orWhere('company_id', $companyId)))
            ->whereIn('code', array_keys($dynamicFields))
            ->get()
            ->keyBy('code');

        // ADR-165: filter out definitions not applicable to the company's market
        if ($marketKey) {
            $definitions = $definitions->filter(function ($def) use ($marketKey) {
                $markets = $def->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        // ADR-168: filter by category (base/hr/domain) — defense-in-depth
        if ($category !== null) {
            $definitions = $definitions->filter(function ($def) use ($category) {
                return ($def->validation_rules['category'] ?? FieldDefinition::CATEGORY_BASE) === $category;
            });
        }

        if ($definitions->isEmpty()) {
            return;
        }

        // Verify activations exist and are enabled
        $activationQuery = FieldActivation::whereIn('field_definition_id', $definitions->pluck('id'))
            ->where('enabled', true);

        if ($scope === FieldDefinition::SCOPE_PLATFORM_USER) {
            $activationQuery->whereNull('company_id');
        } else {
            if ($companyId === null) {
                return; // Cross-tenant prevention: companyId required for non-platform scopes
            }
            $activationQuery->where('company_id', $companyId);
        }

        $activeDefIds = $activationQuery->pluck('field_definition_id')->toArray();

        // ADR-165: resolve dial_code for phone normalization
        $dialCode = '+33';
        if ($marketKey) {
            $market = Market::where('key', $marketKey)->first();
            $dialCode = $market->dial_code ?? '+33';
        }

        DB::transaction(function () use ($model, $dynamicFields, $definitions, $activeDefIds, $dialCode) {
            foreach ($dynamicFields as $code => $value) {
                $definition = $definitions->get($code);
                if (!$definition) {
                    continue;
                }

                // Only write if activation exists and is enabled
                if (!in_array($definition->id, $activeDefIds)) {
                    continue;
                }

                // ADR-165: auto-normalize phone fields to E.164
                if (in_array($code, ['phone', 'emergency_contact_phone', 'company_phone']) && is_string($value) && $value !== '') {
                    $value = PhoneNormalizerService::normalize($value, $dialCode);
                }

                FieldValue::updateOrCreate(
                    [
                        'field_definition_id' => $definition->id,
                        'model_type' => $model->getMorphClass(),
                        'model_id' => $model->getKey(),
                    ],
                    [
                        'value' => $value,
                    ],
                );
            }
        });
    }
}
