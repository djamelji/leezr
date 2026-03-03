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
     * Handles pricing invariants:
     * - Non-addon → clear pricing fields
     * - Model-based metric auto-correction (flat→none, per_seat→users)
     * - Pricing params validation per pricing model
     * - ModulePricingPolicy enforcement
     */
    public function execute(string $key, array $validated): PlatformModule
    {
        $module = PlatformModule::where('key', $key)->firstOrFail();

        // Consistency enforcement: if not addon, clear pricing fields
        if (($validated['pricing_mode'] ?? null) !== 'addon') {
            $validated['pricing_model'] = null;
            $validated['pricing_metric'] = null;
            $validated['pricing_params'] = null;
        }

        // Metric auto-correction based on pricing_model
        if (in_array($validated['pricing_model'] ?? null, ['flat', 'plan_flat'], true)) {
            $validated['pricing_metric'] = 'none';
        } elseif (($validated['pricing_model'] ?? null) === 'per_seat') {
            $validated['pricing_metric'] = 'users';
        }

        // Field-scoped pricing_params validation based on pricing_model
        if (! empty($validated['pricing_model']) && ! empty($validated['pricing_params'])) {
            $paramErrors = static::validatePricingParams(
                $validated['pricing_model'],
                $validated['pricing_params'],
            );

            if ($paramErrors->fails()) {
                throw new ValidationException($paramErrors);
            }
        }

        // Enforce pricing invariants before persisting
        $proposedPricingMode = $validated['pricing_mode'] ?? $module->pricing_mode;

        try {
            ModulePricingPolicy::assertProposedPricingMode($key, $proposedPricingMode);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'pricing_mode' => [$e->getMessage()],
            ]);
        }

        $module->update($validated);

        return $module;
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
