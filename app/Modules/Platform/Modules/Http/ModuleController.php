<?php

namespace App\Modules\Platform\Modules\Http;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Http\JsonResponse;

class ModuleController
{
    /**
     * List company-scope modules in the platform catalog (toggleable).
     */
    public function index(): JsonResponse
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        $modules = PlatformModule::whereIn('key', $companyModuleKeys)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'modules' => $modules,
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
}
