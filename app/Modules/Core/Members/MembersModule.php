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
                    ['key' => 'members', 'title' => 'Members', 'to' => ['name' => 'company-members'], 'icon' => 'tabler-users', 'permission' => 'members.view', 'surface' => 'structure'],
                ],
                routeNames: ['company-members'],
                middlewareKey: 'core.members',
            ),
            permissions: [
                ['key' => 'members.view', 'label' => 'View Members', 'hint' => 'See the team member list and profiles.'],
                ['key' => 'members.invite', 'label' => 'Invite Members', 'hint' => 'Send invitations to new team members.'],
                ['key' => 'members.manage', 'label' => 'Manage Members', 'is_admin' => true, 'hint' => 'Edit profiles, assign roles, and remove members.'],
                ['key' => 'members.credentials', 'label' => 'Manage Credentials', 'is_admin' => true, 'hint' => 'Reset passwords and manage login access.'],
            ],
            bundles: [
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
            ],
            type: 'core',
        );
    }
}
