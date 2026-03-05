<?php

namespace App\Modules\Platform\Modules\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Platform\Modules\ReadModels\PlatformModuleReadModel;
use App\Modules\Platform\Modules\UseCases\TogglePlatformModuleUseCase;
use App\Modules\Platform\Modules\UseCases\UpdateModuleConfigUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModuleController
{
    public function index(): JsonResponse
    {
        return response()->json(PlatformModuleReadModel::catalog());
    }

    public function show(string $key): JsonResponse
    {
        $detail = PlatformModuleReadModel::detail($key);

        if (! $detail) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        return response()->json($detail);
    }

    public function toggle(string $key, TogglePlatformModuleUseCase $useCase): JsonResponse
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (! $manifest) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $result = $useCase->execute($key);

        return response()->json($result);
    }

    /**
     * Sync module registry to database.
     * Already passive: delegates to ModuleRegistry + AuditLogger.
     */
    public function sync(AuditLogger $audit): JsonResponse
    {
        ModuleRegistry::clearCache();
        ModuleRegistry::sync();

        $audit->logPlatform(
            AuditAction::MODULE_SYNCED,
            'platform_module', 'all',
        );

        return response()->json(PlatformModuleReadModel::catalog());
    }

    public function updateConfig(Request $request, string $key, UpdateModuleConfigUseCase $useCase): JsonResponse
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        if (! in_array($key, $companyModuleKeys, true)) {
            return response()->json([
                'message' => 'Only company-scope modules can be configured.',
            ], 422);
        }

        $validated = $request->validate([
            'is_listed' => ['required', 'boolean'],
            'is_sellable' => ['required', 'boolean'],
            'addon_pricing' => ['nullable', 'array'],
            'addon_pricing.pricing_model' => ['required_with:addon_pricing', 'string', Rule::in(['flat', 'plan_flat', 'per_seat', 'usage', 'tiered'])],
            'addon_pricing.pricing_metric' => ['nullable', 'string'],
            'addon_pricing.pricing_params' => ['nullable', 'array'],
            'settings_schema' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'display_name_override' => ['nullable', 'string', 'max:255'],
            'description_override' => ['nullable', 'string', 'max:5000'],
            'min_plan_override' => ['nullable', 'string', Rule::in(['pro', 'business'])],
            'sort_order_override' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'compatible_jobdomains_override' => ['nullable', 'array'],
            'compatible_jobdomains_override.*' => ['string', Rule::exists('jobdomains', 'key')],
            'icon_type' => ['nullable', 'string', Rule::in(['tabler', 'image'])],
            'icon_name' => ['nullable', 'string', 'max:255'],
        ]);

        $module = $useCase->execute($key, $validated);

        return response()->json([
            'message' => 'Module configuration updated.',
            'module' => $module,
        ]);
    }
}
