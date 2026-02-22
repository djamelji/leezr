<?php

namespace App\Modules\Platform\Modules\Http;

use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Jobdomains\Jobdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ModuleController
{
    /**
     * List company-scope modules in the platform catalog (toggleable).
     * Applies override merge: override ?? manifest/synced value.
     */
    public function index(): JsonResponse
    {
        $definitions = ModuleRegistry::forScope('company');
        $companyModuleKeys = array_keys($definitions);

        $modules = PlatformModule::whereIn('key', $companyModuleKeys)
            ->orderBy('sort_order')
            ->get()
            ->map(function (PlatformModule $module) use ($definitions) {
                $manifest = $definitions[$module->key] ?? null;
                $arr = $module->toArray();

                // Effective values (override ?? synced/manifest)
                $arr['name'] = $module->display_name_override ?? $arr['name'];
                $arr['description'] = $module->description_override ?? $arr['description'];
                $arr['sort_order'] = $module->sort_order_override ?? $arr['sort_order'];
                $arr['min_plan'] = $module->min_plan_override ?? $manifest?->minPlan;

                $arr['type'] = $manifest?->type ?? 'addon';
                $arr['compatible_jobdomains'] = $manifest?->compatibleJobdomains;
                $arr['requires'] = $manifest?->requires ?? [];

                // Icon fields (DB override, fallback to manifest)
                $arr['icon_type'] = $module->icon_type ?? $manifest?->iconType ?? 'tabler';
                $arr['icon_name'] = $module->icon_name ?? $manifest?->iconRef ?? 'tabler-puzzle';

                return $arr;
            })
            ->sortBy('sort_order')
            ->values();

        return response()->json([
            'modules' => $modules,
        ]);
    }

    /**
     * Show a module profile with manifest, dependents, and companies using it.
     * Returns effective values, manifest defaults, full permissions/bundles, and icon data.
     */
    public function show(string $key): JsonResponse
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (!$manifest || $manifest->scope !== 'company') {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $platformModule = PlatformModule::where('key', $key)->first();

        if (!$platformModule) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        // Compute reverse dependencies (who depends on this module)
        $dependents = [];
        foreach (ModuleRegistry::forScope('company') as $k => $m) {
            if (in_array($key, $m->requires, true)) {
                $dependents[] = ['key' => $k, 'name' => $m->name];
            }
        }

        // Companies actively using this module
        $companies = CompanyModule::where('module_key', $key)
            ->where('is_enabled_for_company', true)
            ->with('company:id,name,slug,status,plan_key')
            ->get()
            ->pluck('company')
            ->filter()
            ->values();

        // Compatible jobdomains
        $compatibleJobdomains = null;
        if ($manifest->compatibleJobdomains !== null) {
            $compatibleJobdomains = Jobdomain::whereIn('key', $manifest->compatibleJobdomains)
                ->select('id', 'key', 'label')
                ->get();
        }

        // Jobdomains that include this module by default
        $includedByJobdomains = Jobdomain::all()
            ->filter(fn ($jd) => in_array($key, $jd->default_modules ?? [], true))
            ->map(fn ($jd) => ['id' => $jd->id, 'key' => $jd->key, 'label' => $jd->label])
            ->values();

        return response()->json([
            'module' => [
                'key' => $manifest->key,
                // Effective values (override ?? manifest)
                'name' => $platformModule->display_name_override ?? $manifest->name,
                'description' => $platformModule->description_override ?? $manifest->description,
                'min_plan' => $platformModule->min_plan_override ?? $manifest->minPlan,
                'sort_order' => $platformModule->sort_order_override ?? $manifest->sortOrder,
                // Read-only manifest fields
                'type' => $manifest->type,
                'scope' => $manifest->scope,
                'surface' => $manifest->surface,
                'compatible_jobdomains' => $manifest->compatibleJobdomains,
                'requires' => $manifest->requires,
                'is_enabled_globally' => $platformModule->is_enabled_globally,
                'permissions_count' => count($manifest->permissions),
                'bundles_count' => count($manifest->bundles),
                // Full arrays for sidebar cards
                'permissions' => $manifest->permissions,
                'bundles' => $manifest->bundles,
                // Icon (DB override, fallback to manifest)
                'icon_type' => $platformModule->icon_type ?? $manifest->iconType,
                'icon_name' => $platformModule->icon_name ?? $manifest->iconRef,
            ],
            'manifest_defaults' => [
                'name' => $manifest->name,
                'description' => $manifest->description,
                'min_plan' => $manifest->minPlan,
                'sort_order' => $manifest->sortOrder,
            ],
            'platform_config' => $platformModule->only([
                'pricing_mode',
                'is_listed',
                'is_sellable',
                'pricing_model',
                'pricing_metric',
                'pricing_params',
                'settings_schema',
                'notes',
                'display_name_override',
                'description_override',
                'min_plan_override',
                'sort_order_override',
                'icon_type',
                'icon_name',
            ]),
            'dependents' => $dependents,
            'companies' => $companies,
            'compatible_jobdomains_detail' => $compatibleJobdomains,
            'included_by_jobdomains' => $includedByJobdomains,
        ]);
    }

    /**
     * Toggle a module's global availability.
     */
    public function toggle(string $key): JsonResponse
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        if (!in_array($key, $companyModuleKeys, true)) {
            return response()->json([
                'message' => 'Only company-scope modules can be toggled.',
            ], 422);
        }

        $module = PlatformModule::where('key', $key)->first();

        if (!$module) {
            return response()->json([
                'message' => 'Module not found.',
            ], 404);
        }

        $module->is_enabled_globally = !$module->is_enabled_globally;
        $module->save();

        return response()->json([
            'message' => $module->is_enabled_globally ? 'Module enabled globally.' : 'Module disabled globally.',
            'module' => $module,
        ]);
    }

    /**
     * Update a module's commercial/ops configuration (including overrides + icons).
     */
    public function updateConfig(Request $request, string $key): JsonResponse
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        if (!in_array($key, $companyModuleKeys, true)) {
            return response()->json([
                'message' => 'Only company-scope modules can be configured.',
            ], 422);
        }

        $module = PlatformModule::where('key', $key)->first();

        if (!$module) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $pricingModes = ['included', 'addon', 'internal'];
        $pricingModels = ['flat', 'plan_flat', 'per_seat', 'usage', 'tiered'];
        $pricingMetrics = ['none', 'users', 'shipments', 'sms', 'api_calls', 'storage_gb'];

        $validated = $request->validate([
            'pricing_mode' => ['nullable', 'string', Rule::in($pricingModes)],
            'is_listed' => ['required', 'boolean'],
            'is_sellable' => ['required', 'boolean'],
            'pricing_model' => ['nullable', 'string', Rule::in($pricingModels)],
            'pricing_metric' => ['nullable', 'string', Rule::in($pricingMetrics)],
            'pricing_params' => ['nullable', 'array'],
            'settings_schema' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Override fields
            'display_name_override' => ['nullable', 'string', 'max:255'],
            'description_override' => ['nullable', 'string', 'max:5000'],
            'min_plan_override' => ['nullable', 'string', Rule::in(['pro', 'business'])],
            'sort_order_override' => ['nullable', 'integer', 'min:0', 'max:9999'],
            // Icon fields
            'icon_type' => ['nullable', 'string', Rule::in(['tabler', 'image'])],
            'icon_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Consistency enforcement: if not addon, clear pricing fields
        if (($validated['pricing_mode'] ?? null) !== 'addon') {
            $validated['pricing_model'] = null;
            $validated['pricing_metric'] = null;
            $validated['pricing_params'] = null;
        }

        // Metric auto-correction based on pricing_model
        if (in_array($validated['pricing_model'] ?? null, ['flat', 'plan_flat'], true)) {
            $validated['pricing_metric'] = 'none';
        }
        elseif (($validated['pricing_model'] ?? null) === 'per_seat') {
            $validated['pricing_metric'] = 'users';
        }

        // Field-scoped pricing_params validation based on pricing_model
        if (!empty($validated['pricing_model']) && !empty($validated['pricing_params'])) {
            $paramErrors = $this->validatePricingParams(
                $validated['pricing_model'],
                $validated['pricing_params'],
            );

            if ($paramErrors->fails()) {
                return response()->json([
                    'message' => 'Invalid pricing parameters.',
                    'errors' => $paramErrors->errors(),
                ], 422);
            }
        }

        $module->update($validated);

        return response()->json([
            'message' => 'Module configuration updated.',
            'module' => $module,
        ]);
    }

    /**
     * Validate pricing_params structure based on pricing_model.
     * Returns a Validator instance for field-scoped errors.
     */
    private function validatePricingParams(string $model, array $params): \Illuminate\Validation\Validator
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
