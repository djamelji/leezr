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
                // ADR-598 LOT B: surfaced as a child of the Governance → Access group.
                navItems: [
                    ['key' => 'access-roles', 'title' => 'Roles & Permissions', 'to' => ['name' => 'platform-access-tab', 'params' => ['tab' => 'roles']], 'icon' => 'tabler-lock-cog', 'permission' => 'manage_roles', 'parent' => 'platform-access', 'sort' => 20],
                ],
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
