<?php

namespace App\Modules\Platform\Realtime;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class RealtimeModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.realtime',
            name: 'Realtime Backbone',
            description: 'SSE backbone governance: connections, metrics, kill switch, event monitoring',
            surface: 'structure',
            sortOrder: 95,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'realtime', 'title' => 'Realtime', 'to' => ['name' => 'platform-realtime'], 'icon' => 'tabler-broadcast', 'permission' => 'realtime.view'],
                ],
                routeNames: ['platform-realtime'],
            ),
            permissions: [
                ['key' => 'realtime.view', 'label' => 'View Realtime Dashboard'],
                ['key' => 'realtime.manage', 'label' => 'Manage Realtime Settings'],
                ['key' => 'realtime.metrics.view', 'label' => 'View Event Metrics'],
                ['key' => 'realtime.connections.view', 'label' => 'View Active Connections'],
                ['key' => 'realtime.governance', 'label' => 'Kill Switch & Flush Operations'],
            ],
            bundles: [
                [
                    'key' => 'realtime.full',
                    'label' => 'Full Realtime Governance',
                    'hint' => 'Monitor connections, metrics, and control the SSE backbone.',
                    'permissions' => ['realtime.view', 'realtime.manage', 'realtime.metrics.view', 'realtime.connections.view', 'realtime.governance'],
                ],
                [
                    'key' => 'realtime.readonly',
                    'label' => 'Realtime Read-Only',
                    'hint' => 'View realtime metrics and connection stats.',
                    'permissions' => ['realtime.view', 'realtime.metrics.view', 'realtime.connections.view'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
            iconRef: 'tabler-broadcast',
        );
    }
}
