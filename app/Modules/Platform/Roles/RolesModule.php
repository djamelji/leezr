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
                navItems: [],
                routeNames: ['platform-access-tab'],
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
            scope: 'admin',
            type: 'internal',
        );
    }
}
