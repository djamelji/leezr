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
                // ADR-598 LOT F: Notifications surfaced as an independent child of Cockpit → Messaging.
                navItems: [
                    ['key' => 'messaging-notifications', 'title' => 'Notifications', 'to' => ['name' => 'platform-messaging-tab', 'params' => ['tab' => 'notifications']], 'icon' => 'tabler-bell', 'permission' => 'manage_notifications', 'parent' => 'platform-messaging', 'sort' => 20],
                ],
                routeNames: ['platform-notifications', 'platform-messaging-tab'],
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
