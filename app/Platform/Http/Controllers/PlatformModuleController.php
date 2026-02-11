<?php

namespace App\Platform\Http\Controllers;

use App\Core\Modules\PlatformModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformModuleController
{
    /**
     * List all modules in the platform catalog.
     */
    public function index(): JsonResponse
    {
        $modules = PlatformModule::orderBy('sort_order')->get();

        return response()->json([
            'modules' => $modules,
        ]);
    }

    /**
     * Toggle a module's global availability.
     */
    public function toggle(string $key): JsonResponse
    {
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
