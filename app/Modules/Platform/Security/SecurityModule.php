<?php

namespace App\Modules\Platform\Security;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class SecurityModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.security',
            name: 'Security & Monitoring',
            description: 'Anomaly detection, security alert management, and realtime backbone monitoring',
            surface: 'structure',
            sortOrder: 94,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'security', 'title' => 'Security & Monitoring', 'to' => ['name' => 'platform-security'], 'icon' => 'tabler-shield-lock', 'permission' => 'manage_security_alerts'],
                ],
                routeNames: ['platform-security'],
            ),
            permissions: [
                ['key' => 'manage_security_alerts', 'label' => 'Manage Security Alerts'],
                ['key' => 'manage_realtime', 'label' => 'Manage Realtime'],
            ],
            bundles: [
                [
                    'key' => 'security.alerts',
                    'label' => 'Security Alert Management',
                    'hint' => 'View, acknowledge, and resolve security alerts.',
                    'permissions' => ['manage_security_alerts'],
                ],
                [
                    'key' => 'security.realtime',
                    'label' => 'Realtime Governance',
                    'hint' => 'Monitor SSE connections, metrics, and control the realtime backbone.',
                    'permissions' => ['manage_realtime'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
