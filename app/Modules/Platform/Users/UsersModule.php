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
                    ['key' => 'platform-users', 'title' => 'Platform Users', 'to' => ['name' => 'platform-users'], 'icon' => 'tabler-user-shield', 'permission' => 'manage_platform_users'],
                ],
                routeNames: ['platform-users'],
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
            scope: 'platform',
            type: 'internal',
        );
    }
}
