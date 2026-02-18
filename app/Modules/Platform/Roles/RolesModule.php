<?php

namespace App\Modules\Platform\Roles;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class RolesModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.roles',
            name: 'Roles',
            description: 'Manage platform roles and permissions',
            surface: 'structure',
            sortOrder: 30,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'roles', 'title' => 'Roles', 'to' => ['name' => 'platform-roles'], 'icon' => 'tabler-shield-lock', 'permission' => 'manage_roles'],
                ],
                routeNames: ['platform-roles'],
            ),
            permissions: [
                ['key' => 'manage_roles', 'label' => 'Manage Roles'],
            ],
            bundles: [
                [
                    'key' => 'roles.access_control',
                    'label' => 'Access Control',
                    'hint' => 'Manage platform roles and permission assignments.',
                    'permissions' => ['manage_roles'],
                ],
            ],
            scope: 'platform',
            type: 'internal',
        );
    }
}
