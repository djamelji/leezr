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
                    ['key' => 'platform-access', 'title' => 'Access Management', 'to' => ['name' => 'platform-access-tab', 'params' => ['tab' => 'users']], 'icon' => 'tabler-user-shield', 'permission' => 'manage_platform_users', 'group' => 'administration', 'sort' => 70],
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
