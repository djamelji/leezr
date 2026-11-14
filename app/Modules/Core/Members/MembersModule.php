<?php

namespace App\Modules\Core\Members;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class MembersModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.members',
            name: 'Members',
            description: 'Manage company members and roles',
            surface: 'structure',
            sortOrder: 10,
            capabilities: new Capabilities(
                navItems: [
                    // ADR-754 (P2) — entrée UNIQUE « Collaborateurs » (remplace Members + Employees),
                    // portée par le Core. Visible dès qu'une facette est autorisée (accès OU RH).
                    ['key' => 'people', 'title' => 'Collaborators', 'to' => ['name' => 'company-people'], 'icon' => 'tabler-users', 'anyPermission' => ['members.view', 'workforce.view', 'roles.view'], 'surface' => 'structure', 'group' => 'team', 'sort' => 10],
                ],
                routeNames: ['company-people', 'company-members', 'company-members-id'],
                actions: [ // ADR-775
                    ['key' => 'team.add-person', 'space' => 'team', 'icon' => 'tabler-user-plus', 'to' => ['name' => 'company-people', 'query' => ['action' => 'invite']], 'permission' => 'members.invite', 'home' => true, 'primary' => true, 'sort' => 10],
                ],
                middlewareKey: 'core.members',
            ),
            permissions: [
                ['key' => 'members.view', 'label' => 'View Members', 'hint' => 'See the team member list and profiles.'],
                ['key' => 'members.invite', 'label' => 'Invite Members', 'hint' => 'Send invitations to new team members.'],
                ['key' => 'members.manage', 'label' => 'Manage Members', 'is_admin' => true, 'hint' => 'Edit profiles, assign roles, and remove members.'],
                ['key' => 'members.credentials', 'label' => 'Manage Credentials', 'is_admin' => true, 'hint' => 'Reset passwords and manage login access.'],
                ['key' => 'members.sensitive_read', 'label' => 'Read Sensitive Data', 'is_admin' => true, 'hint' => 'Access sensitive member fields (IBAN, social security number).'],
            ],
            bundles: [
                // ADR-777 : « voir l'équipe » sans le pouvoir d'inviter — pour les rôles de terrain.
                [
                    'key' => 'members.team_view',
                    'label' => 'Team Directory',
                    'hint' => 'View the team.',
                    'permissions' => ['members.view'],
                ],
                [
                    'key' => 'members.team_access',
                    'label' => 'Team Access',
                    'hint' => 'View the team and invite new members.',
                    'permissions' => ['members.view', 'members.invite'],
                ],
                [
                    'key' => 'members.team_management',
                    'label' => 'Team Management',
                    'hint' => 'Edit profiles, assign roles, and manage credentials.',
                    'permissions' => ['members.manage', 'members.credentials'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'members.sensitive_data',
                    'label' => 'Sensitive Data Access',
                    'hint' => 'Read sensitive member fields (IBAN, social security).',
                    'permissions' => ['members.sensitive_read'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
