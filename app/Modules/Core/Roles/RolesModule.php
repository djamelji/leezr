<?php

namespace App\Modules\Core\Roles;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class RolesModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.roles',
            name: 'Roles',
            description: 'Manage company roles and permission assignments',
            surface: 'structure',
            sortOrder: 15,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'company-roles', 'title' => 'Roles', 'to' => ['name' => 'company-roles'], 'icon' => 'tabler-shield-lock', 'permission' => 'roles.view', 'surface' => 'structure'],
                ],
                routeNames: ['company-roles'],
                middlewareKey: 'core.roles',
            ),
            permissions: [
                ['key' => 'roles.view', 'label' => 'View Roles', 'is_admin' => true, 'hint' => 'See role list and permission assignments.'],
                ['key' => 'roles.manage', 'label' => 'Manage Roles', 'is_admin' => true, 'hint' => 'Create, edit, delete roles and assign permissions.'],
            ],
            bundles: [
                [
                    'key' => 'roles.governance',
                    'label' => 'Role Governance',
                    'hint' => 'View, create, edit, and delete roles with permission assignments.',
                    'permissions' => ['roles.view', 'roles.manage'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
