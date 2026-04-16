<?php

namespace App\Modules\Platform\Notifications;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class NotificationsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.notifications',
            name: 'Notifications',
            description: 'Manage notification topics and delivery channels.',
            surface: 'governance',
            sortOrder: 91,
            capabilities: new Capabilities(
                navItems: [
                    [
                        'key' => 'notifications',
                        'title' => 'Notifications',
                        'to' => ['name' => 'platform-notifications'],
                        'icon' => 'tabler-bell-cog',
                        'permission' => 'manage_notifications',
                        'group' => 'administration',
                        'sort' => 72,
                    ],
                ],
                routeNames: ['platform-notifications'],
            ),
            permissions: [
                ['key' => 'manage_notifications', 'label' => 'Manage Notification Topics'],
            ],
            bundles: [
                [
                    'key' => 'notifications.admin',
                    'label' => 'Notification Administration',
                    'permissions' => ['manage_notifications'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
