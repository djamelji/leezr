<?php

namespace App\Modules\Platform\Modules\UseCases;

use App\Core\Modules\PlatformModule;
use App\Core\Modules\Pricing\ModulePricingPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateModuleConfigUseCase
{
    /**
     * Update a module's commercial/ops configuration.
     *
     * ADR-206: addon_pricing is a single JSON field replacing
     * pricing_mode + pricing_model + pricing_metric + pricing_params.
     */
    public function execute(string $key, array $validated): PlatformModule
    {
        $module = PlatformModule::where('key', $key)->firstOrFail();

        // Validate addon_pricing structure if present
        if (!empty($validated['addon_pricing'])) {
            $paramErrors = static::validateAddonPricing($validated['addon_pricing']);

            if ($paramErrors->fails()) {
                throw new ValidationException($paramErrors);
            }
        }

        // Enforce pricing invariants before persisting
        $proposedAddonPricing = array_key_exists('addon_pricing', $validated)
            ? $validated['addon_pricing']
            : $module->addon_pricing;

        try {
            ModulePricingPolicy::assertAddonPricing($key, $proposedAddonPricing);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'addon_pricing' => [$e->getMessage()],
            ]);
        }

        // ADR-821 — cohérence commerciale des dépendances : un module inclus dans une offre exige que ses
        // dépendances le soient aussi ; une dépendance premium ne devient jamais gratuite par accident. Seuls les
        // NOUVEAUX écarts créés par cette modification sont refusés (l'existant reste signalé, non bloquant).
        if (array_key_exists('min_plan_override', $validated) || array_key_exists('addon_pricing', $validated)) {
            $override = [$key => [
                'min_plan' => array_key_exists('min_plan_override', $validated) ? ($validated['min_plan_override'] ?? (\App\Core\Modules\ModuleRegistry::definitions()[$key]->minPlan ?? null)) : ($module->min_plan_override ?? (\App\Core\Modules\ModuleRegistry::definitions()[$key]->minPlan ?? null)),
                'addon_pricing' => $proposedAddonPricing,
            ]];
            $sig = fn ($v) => $v['plan'].'|'.$v['module'].'|'.$v['dependency'];
            $before = array_map($sig, \App\Core\Modules\ModuleDependencyResolver::planCoverage());
            $new = array_values(array_filter(\App\Core\Modules\ModuleDependencyResolver::planCoverage($override), fn ($v) => ! in_array($sig($v), $before, true)));
            if ($new !== []) {
                throw ValidationException::withMessages(['min_plan_override' => [__('modules.dependency_not_covered', [
                    'module' => $new[0]['module'], 'dependency' => $new[0]['dependency'], 'plan' => $new[0]['plan']])]]);
            }
        }

        $module->update($validated);

        return $module;
    }

    /**
     * Validate addon_pricing structure.
     */
    private static function validateAddonPricing(array $addonPricing): \Illuminate\Validation\Validator
    {
        $rules = [
            'pricing_model' => ['required', 'string', 'in:flat,plan_flat,per_seat,usage,tiered'],
            'pricing_metric' => ['nullable', 'string'],
            'pricing_params' => ['nullable', 'array'],
        ];

        $validator = Validator::make($addonPricing, $rules);

        if ($validator->fails()) {
            return $validator;
        }

        // Field-scoped pricing_params validation based on pricing_model
        $model = $addonPricing['pricing_model'];
        $params = $addonPricing['pricing_params'] ?? [];

        if (!empty($params)) {
            return static::validatePricingParams($model, $params);
        }

        return $validator;
    }

    /**
     * Validate pricing_params structure based on pricing_model.
     */
    private static function validatePricingParams(string $model, array $params): \Illuminate\Validation\Validator
    {
        $rules = match ($model) {
            'flat' => [
                'price_monthly' => ['required', 'numeric', 'min:0'],
            ],
            'plan_flat' => [
                'starter' => ['nullable', 'numeric', 'min:0'],
                'pro' => ['nullable', 'numeric', 'min:0'],
                'business' => ['nullable', 'numeric', 'min:0'],
            ],
            'per_seat' => [
                'included' => ['required', 'array'],
                'included.starter' => ['nullable', 'integer', 'min:0'],
                'included.pro' => ['nullable', 'integer', 'min:0'],
                'included.business' => ['nullable', 'integer', 'min:0'],
                'overage_unit_price' => ['required', 'array'],
                'overage_unit_price.starter' => ['nullable', 'numeric', 'min:0'],
                'overage_unit_price.pro' => ['nullable', 'numeric', 'min:0'],
                'overage_unit_price.business' => ['nullable', 'numeric', 'min:0'],
            ],
            'usage' => [
                'unit_price' => ['required', 'numeric', 'min:0'],
            ],
            'tiered' => [
                'tiers' => ['required', 'array', 'min:1'],
                'tiers.*.up_to' => ['nullable', 'integer', 'min:0'],
                'tiers.*.price' => ['required', 'numeric', 'min:0'],
            ],
            default => [],
        };

        return Validator::make($params, $rules);
    }
}
