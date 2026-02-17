<?php

namespace App\Company\RBAC;

use App\Core\Modules\ModuleRegistry;

/**
 * Single source of truth for all company-level permissions.
 * Aggregates permissions declared by each module in ModuleRegistry.
 * Mirrors the Platform PermissionCatalog pattern.
 */
class CompanyPermissionCatalog
{
    /**
     * All company permissions, aggregated from module definitions.
     *
     * @return array<array{key: string, label: string, module_key: string}>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (ModuleRegistry::definitions() as $moduleKey => $manifest) {
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
     * Get permissions for a specific module.
     *
     * @return array<array{key: string, label: string, module_key: string}>
     */
    public static function forModule(string $moduleKey): array
    {
        return array_values(array_filter(
            static::all(),
            fn ($p) => $p['module_key'] === $moduleKey,
        ));
    }

    /**
     * Sync all permissions to the company_permissions table.
     * Called from seeder or artisan command.
     */
    public static function sync(): void
    {
        foreach (static::all() as $permission) {
            CompanyPermission::updateOrCreate(
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
