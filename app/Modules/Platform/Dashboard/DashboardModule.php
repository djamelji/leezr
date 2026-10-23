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
                    ['key' => 'dashboard', 'title' => 'Dashboard', 'to' => ['name' => 'platform-dashboard-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-dashboard', 'permission' => 'view_dashboard', 'group' => 'cockpit', 'sort' => 1],
                ],
                routeNames: ['platform', 'platform-dashboard-tab'],
            ),
            // ADR-595 (LOT 1): close the A-01 hole — dashboard endpoints are permission-gated.
            // Base `view_dashboard` keeps the landing non-exclusive; the two sensitive aggregates
            // (system health, usage monitoring) require their own permission.
            permissions: [
                ['key' => 'view_dashboard', 'label' => 'View Dashboard'],
                ['key' => 'view_system_health', 'label' => 'View System Health'],
                ['key' => 'view_usage_monitoring', 'label' => 'View Usage Monitoring'],
            ],
            bundles: [
                [
                    'key' => 'dashboard.base',
                    'label' => 'Dashboard Access',
                    'hint' => 'Access the platform dashboard, personal widgets and global search.',
                    'permissions' => ['view_dashboard'],
                ],
                [
                    'key' => 'dashboard.supervision',
                    'label' => 'Dashboard & System Supervision',
                    'hint' => 'Dashboard plus system health and usage monitoring.',
                    'permissions' => ['view_dashboard', 'view_system_health', 'view_usage_monitoring'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
