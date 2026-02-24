<?php

namespace App\Core\Modules;

/**
 * Enable/disable admin-scope modules at the platform level.
 *
 * Admin modules only have a global toggle (PlatformModule.is_enabled_globally).
 * Modules of type 'internal' cannot be toggled — they are always on.
 */
class AdminModuleService
{
    /**
     * Enable an admin-scope module globally.
     *
     * @return array{success: bool, status: int, data: array}
     */
    public static function enable(string $key): array
    {
        $validation = static::validateToggleable($key);

        if ($validation !== null) {
            return $validation;
        }

        $module = PlatformModule::where('key', $key)->first();
        $module->is_enabled_globally = true;
        $module->save();

        return [
            'success' => true,
            'status' => 200,
            'data' => ['message' => 'Admin module enabled globally.'],
        ];
    }

    /**
     * Disable an admin-scope module globally.
     *
     * @return array{success: bool, status: int, data: array}
     */
    public static function disable(string $key): array
    {
        $validation = static::validateToggleable($key);

        if ($validation !== null) {
            return $validation;
        }

        $module = PlatformModule::where('key', $key)->first();
        $module->is_enabled_globally = false;
        $module->save();

        return [
            'success' => true,
            'status' => 200,
            'data' => ['message' => 'Admin module disabled globally.'],
        ];
    }

    /**
     * Validate that the module exists, is admin-scope, and is toggleable.
     *
     * @return array{success: bool, status: int, data: array}|null null if valid
     */
    private static function validateToggleable(string $key): ?array
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (!$manifest || $manifest->scope !== 'admin') {
            return [
                'success' => false,
                'status' => 404,
                'data' => ['message' => 'Admin module not found.'],
            ];
        }

        if ($manifest->type === 'internal') {
            return [
                'success' => false,
                'status' => 422,
                'data' => ['message' => 'Internal modules cannot be toggled.'],
            ];
        }

        $module = PlatformModule::where('key', $key)->first();

        if (!$module) {
            return [
                'success' => false,
                'status' => 404,
                'data' => ['message' => 'Module not found in platform catalog.'],
            ];
        }

        return null;
    }
}
