<?php

namespace App\Modules\Platform\Dashboard;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class DashboardModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.dashboard',
            name: 'Dashboard',
            description: 'Platform dashboard overview',
            surface: 'structure',
            sortOrder: 1,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'dashboard', 'title' => 'Dashboard', 'to' => ['name' => 'platform-dashboard-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-dashboard', 'group' => 'cockpit', 'sort' => 1],
                ],
                routeNames: ['platform', 'platform-dashboard-tab'],
            ),
            permissions: [],
            bundles: [],
            scope: 'admin',
            type: 'internal',
        );
    }
}
