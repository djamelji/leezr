<?php

namespace App\Modules\Core\Notifications;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class NotificationsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.notifications',
            name: 'Notifications',
            description: 'In-app notification inbox and preferences.',
            surface: 'core',
            sortOrder: 6,
            capabilities: new Capabilities(
                navItems: [],  // No nav item — access via navbar bell
            ),
            permissions: [],
            bundles: [],
            scope: 'company',
            type: 'core',
        );
    }
}
