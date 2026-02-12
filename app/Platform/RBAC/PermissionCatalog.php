<?php

namespace App\Platform\RBAC;

class PermissionCatalog
{
    /**
     * Single source of truth for all platform permissions.
     *
     * @return array<array{key: string, label: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'manage_companies', 'label' => 'Manage Companies'],
            ['key' => 'view_company_users', 'label' => 'View Company Users'],
            ['key' => 'manage_platform_users', 'label' => 'Manage Platform Users'],
            ['key' => 'manage_roles', 'label' => 'Manage Roles'],
            ['key' => 'manage_modules', 'label' => 'Manage Modules'],
        ];
    }

    /**
     * @return array<string>
     */
    public static function keys(): array
    {
        return array_column(static::all(), 'key');
    }
}
