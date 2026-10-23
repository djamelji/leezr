<?php

namespace App\Modules\Platform\Alerts;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * Alert Center module — platform-wide alert monitoring and triage.
 *
 * Provides the sidebar nav item for the alert center page.
 * Alerts are surfaced from billing anomalies, security events,
 * subscription issues, and system health checks.
 */
class AlertsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.alerts',
            name: 'Alert Center',
            description: 'Platform-wide alert center — monitor and triage critical alerts across all systems.',
            surface: 'governance',
            sortOrder: 3,
            capabilities: new Capabilities(
                navItems: [
                    // ADR-598 LOT F: 'operations' is a collapsible parent (no permission) — it shows if
                    // ANY child is authorized. Children are contributed independently per module.
                    ['key' => 'operations', 'title' => 'Operations', 'icon' => 'tabler-settings-cog', 'group' => 'system', 'sort' => 60],
                    ['key' => 'ops-health', 'title' => 'System Health', 'to' => ['name' => 'platform-operations-tab', 'params' => ['tab' => 'health']], 'icon' => 'tabler-heartbeat', 'permission' => 'view_system_health', 'parent' => 'operations', 'sort' => 10],
                    ['key' => 'ops-alerts', 'title' => 'Alerts', 'to' => ['name' => 'platform-operations-tab', 'params' => ['tab' => 'alerts']], 'icon' => 'tabler-bell-ringing', 'permission' => 'view_alerts', 'parent' => 'operations', 'sort' => 20],
                    ['key' => 'ops-usage', 'title' => 'Usage Monitoring', 'to' => ['name' => 'platform-operations-tab', 'params' => ['tab' => 'usage']], 'icon' => 'tabler-chart-bar', 'permission' => 'view_usage_monitoring', 'parent' => 'operations', 'sort' => 50],
                ],
                routeNames: ['platform-alerts', 'platform-operations-tab'],
            ),
            permissions: [
                ['key' => 'view_alerts', 'label' => 'View Alerts'],
                ['key' => 'manage_alerts', 'label' => 'Manage Alerts'],
            ],
            bundles: [
                [
                    'key' => 'alerts.readonly',
                    'label' => 'Alerts Read-Only',
                    'permissions' => ['view_alerts'],
                ],
                [
                    'key' => 'alerts.full',
                    'label' => 'Full Alert Management',
                    'permissions' => ['view_alerts', 'manage_alerts'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
