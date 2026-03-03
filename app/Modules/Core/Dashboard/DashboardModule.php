<?php

namespace App\Modules\Core\Dashboard;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class DashboardModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.dashboard',
            name: 'Dashboard',
            description: 'Company dashboard with customizable widget grid',
            surface: 'structure',
            sortOrder: 1,
            capabilities: new Capabilities(
                navItems: [
                    [
                        'key' => 'dashboard',
                        'title' => 'Dashboard',
                        'to' => ['name' => 'dashboard'],
                        'icon' => 'tabler-smart-home',
                    ],
                ],
                routeNames: [], // ADR-149: dashboard always accessible, no module gate
            ),
            permissions: [],
            bundles: [],
            scope: 'company',
            type: 'core',
            iconRef: 'tabler-smart-home',
        );
    }
}
