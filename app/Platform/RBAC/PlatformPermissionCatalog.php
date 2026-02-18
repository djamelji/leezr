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
     * @return array<array{key: string, label: string, module_key: string}>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (ModuleRegistry::forScope('platform') as $moduleKey => $manifest) {
            foreach ($manifest->permissions as $permission) {
                $permissions[] = [
                    'key' => $permission['key'],
                    'label' => $permission['label'],
                    'module_key' => $moduleKey,
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
                ['label' => $permission['label']],
            );
        }
    }
}
