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
                    ['key' => 'dashboard', 'title' => 'Dashboard', 'to' => ['name' => 'platform'], 'icon' => 'tabler-dashboard'],
                ],
                routeNames: ['platform'],
            ),
            permissions: [],
            bundles: [],
            scope: 'platform',
            type: 'internal',
        );
    }
}
