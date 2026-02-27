<?php

namespace App\Platform\RBAC;

use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformPermission;

/**
 * Module-driven permission catalog for platform scope.
 * Aggregates permissions from all platform-scope module manifests.
 */
class PlatformPermissionCatalog
{
    /**
     * All platform permissions, aggregated from module manifests.
     *
     * @return array<array{key: string, label: string, module_key: string, is_admin: bool}>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (ModuleRegistry::forScope('admin') as $moduleKey => $manifest) {
            foreach ($manifest->permissions as $permission) {
                $permissions[] = [
                    'key' => $permission['key'],
                    'label' => $permission['label'],
                    'module_key' => $moduleKey,
                    'is_admin' => $permission['is_admin'] ?? false,
                ];
            }
        }

        return $permissions;
    }

    /**
     * @return array<string>
     */
    public static function keys(): array
    {
        return array_column(static::all(), 'key');
    }

    /**
     * Sync platform permissions to the database.
     */
    public static function sync(): void
    {
        foreach (static::all() as $permission) {
            PlatformPermission::updateOrCreate(
                ['key' => $permission['key']],
                [
                    'label' => $permission['label'],
                    'module_key' => $permission['module_key'],
                    'is_admin' => $permission['is_admin'],
                ],
            );
        }
    }
}
