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
                navItems: [],
                routeNames: ['platform-realtime', 'platform-operations-tab'],
            ),
            // ADR-597 (LOT 3): removed dead permissions (realtime.view, realtime.manage) —
            // only realtime.metrics.view / realtime.connections.view / realtime.governance are enforced.
            permissions: [
                ['key' => 'realtime.metrics.view', 'label' => 'View Event Metrics'],
                ['key' => 'realtime.connections.view', 'label' => 'View Active Connections'],
                ['key' => 'realtime.governance', 'label' => 'Kill Switch & Flush Operations'],
            ],
            bundles: [
                [
                    'key' => 'realtime.full',
                    'label' => 'Full Realtime Governance',
                    'hint' => 'Monitor connections, metrics, and control the SSE backbone.',
                    'permissions' => ['realtime.metrics.view', 'realtime.connections.view', 'realtime.governance'],
                ],
                [
                    'key' => 'realtime.readonly',
                    'label' => 'Realtime Read-Only',
                    'hint' => 'View realtime metrics and connection stats.',
                    'permissions' => ['realtime.metrics.view', 'realtime.connections.view'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
            iconRef: 'tabler-broadcast',
        );
    }
}
