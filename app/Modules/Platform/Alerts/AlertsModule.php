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
                    // ADR-601: Operations hub is reachable by holders of ANY operations capability
                    // (so view_ai / view_usage_monitoring users reach the Usage & Consumption page).
                    // The hub page itself falls back to the first tab the user is allowed to see.
                    ['key' => 'operations', 'title' => 'Operations', 'to' => ['name' => 'platform-operations-tab', 'params' => ['tab' => 'health']], 'icon' => 'tabler-settings-cog', 'anyPermission' => ['view_system_health', 'view_alerts', 'security.alerts.view', 'view_ai', 'view_usage_monitoring', 'manage_automations', 'realtime.metrics.view'], 'group' => 'system', 'sort' => 60],
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
