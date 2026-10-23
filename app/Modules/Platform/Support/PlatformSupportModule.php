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
                    // ADR-598 LOT F: 'communications' is a collapsible parent (no permission) that
                    // shows if any child (Support / Documentation) is authorized.
                    ['key' => 'communications', 'title' => 'Communications', 'icon' => 'tabler-message-circle-cog', 'group' => 'clients', 'sort' => 40],
                    ['key' => 'comms-support', 'title' => 'Support', 'to' => ['name' => 'platform-communications-tab', 'params' => ['tab' => 'support']], 'icon' => 'tabler-headset', 'permission' => 'manage_support', 'parent' => 'communications', 'sort' => 10],
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
