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
    /**
     * ADR-832 — a permission is a technical definition owned by the code (key, label, module, admin flag) :
     * always aligned, changes reported. Returns the keys created by this run (offered to the roles that follow
     * the catalog, never re-imposed afterwards).
     *
     * @return list<string>
     */
    public static function sync(): array
    {
        $created = [];
        foreach (static::all() as $permission) {
            $row = \App\Core\System\SystemSync::reconcile('platform_permissions', PlatformPermission::class, ['key' => $permission['key']], code: [
                'label' => $permission['label'],
                'module_key' => $permission['module_key'],
                'is_admin' => $permission['is_admin'],
            ]);
            if ($row->wasRecentlyCreated) {
                $created[] = $permission['key'];
            }
        }

        return $created;
    }
}
