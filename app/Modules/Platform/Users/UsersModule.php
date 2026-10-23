<?php

namespace App\Modules\Platform\Users;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class UsersModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.users',
            name: 'Platform Users',
            description: 'Manage platform administrators',
            surface: 'structure',
            sortOrder: 20,
            capabilities: new Capabilities(
                navItems: [
                    // ADR-598 LOT B: 'platform-access' is a collapsible parent under Governance.
                    ['key' => 'platform-access', 'title' => 'Access Management', 'icon' => 'tabler-user-shield', 'group' => 'governance', 'sort' => 40],
                    ['key' => 'access-users', 'title' => 'Users', 'to' => ['name' => 'platform-access-tab', 'params' => ['tab' => 'users']], 'icon' => 'tabler-users', 'permission' => 'manage_platform_users', 'parent' => 'platform-access', 'sort' => 10],
                ],
                routeNames: ['platform-access-tab', 'platform-users-id'],
            ),
            permissions: [
                ['key' => 'manage_platform_users', 'label' => 'Manage Platform Users'],
                ['key' => 'manage_platform_user_credentials', 'label' => 'Manage Platform User Credentials'],
            ],
            bundles: [
                [
                    'key' => 'users.management',
                    'label' => 'User Management',
                    'hint' => 'Manage platform administrators and their credentials.',
                    'permissions' => ['manage_platform_users', 'manage_platform_user_credentials'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
