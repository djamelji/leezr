<?php

namespace App\Modules\Platform\Support;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class PlatformSupportModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.support',
            name: 'Support',
            description: 'Manage support tickets from companies.',
            surface: 'governance',
            sortOrder: 92,
            capabilities: new Capabilities(
                navItems: [
                    [
                        'key' => 'communications',
                        'title' => 'Communications',
                        'to' => ['name' => 'platform-communications-tab', 'params' => ['tab' => 'support']],
                        'icon' => 'tabler-message-circle-cog',
                        'group' => 'clients',
                        'sort' => 40,
                    ],
                ],
                routeNames: ['platform-support', 'platform-support-id', 'platform-communications-tab'],
                footerLinks: [
                    [
                        'key' => 'footer-support',
                        'label' => 'footer.support',
                        'to' => ['name' => 'platform-communications-tab', 'params' => ['tab' => 'support']],
                        'icon' => 'tabler-message-circle-cog',
                        'permission' => 'manage_support',
                        'sortOrder' => 10,
                    ],
                ],
            ),
            permissions: [
                ['key' => 'manage_support', 'label' => 'Manage Support Tickets'],
                ['key' => 'assign_support', 'label' => 'Assign Support Tickets'],
            ],
            bundles: [
                [
                    'key' => 'support.admin',
                    'label' => 'Support Administration',
                    'permissions' => ['manage_support', 'assign_support'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
