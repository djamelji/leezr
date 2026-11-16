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
                // ADR-766 (M7) — Roles consolidé dans « Collaborateurs » (People expose l'onglet Rôles via
                // son `anyPermission` incluant `roles.view`). Plus d'entrée de nav dédiée ; la route reste
                // enregistrée comme ALIAS (redirige côté front vers People, onglet Rôles).
                // ADR-781 (UX5) — contributed to the Settings hub only (the Settings space never lists it in the menu).
                navItems: [
                    ['key' => 'settings-roles', 'title' => 'Roles & permissions', 'to' => ['name' => 'company-people', 'query' => ['tab' => 'roles']], 'icon' => 'tabler-shield-lock', 'permission' => 'roles.view', 'surface' => 'structure', 'group' => 'settings', 'sort' => 40, 'settings' => ['section' => 'access', 'level' => 'regular']],
                ],
                routeNames: ['company-roles'],
                priorityProviders: [\App\Modules\Core\Roles\Home\PendingAccessHomePriorities::class], // ADR-809
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
