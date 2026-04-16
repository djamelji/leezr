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
                    [
                        'key' => 'platform-alerts',
                        'title' => 'Alerts',
                        'to' => ['name' => 'platform-alerts'],
                        'icon' => 'tabler-bell-ringing',
                        'group' => 'cockpit',
                        'sort' => 3,
                    ],
                ],
                routeNames: ['platform-alerts'],
            ),
            permissions: [],
            bundles: [],
            scope: 'admin',
            type: 'platform',
        );
    }
}
