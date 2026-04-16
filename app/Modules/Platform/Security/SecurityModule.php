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
            name: 'Security',
            description: 'Anomaly detection and security alert management',
            surface: 'structure',
            sortOrder: 94,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
            ),
            permissions: [
                ['key' => 'security.view', 'label' => 'View Security Dashboard'],
                ['key' => 'security.manage', 'label' => 'Manage Security Settings'],
                ['key' => 'security.alerts.view', 'label' => 'View Security Alerts'],
                ['key' => 'security.alerts.manage', 'label' => 'Manage Security Alerts'],
                ['key' => 'security.audit.view', 'label' => 'View Security Audit'],
            ],
            bundles: [
                [
                    'key' => 'security.full',
                    'label' => 'Full Security Management',
                    'hint' => 'Complete security governance access.',
                    'permissions' => ['security.view', 'security.manage', 'security.alerts.view', 'security.alerts.manage', 'security.audit.view'],
                ],
                [
                    'key' => 'security.readonly',
                    'label' => 'Security Read-Only',
                    'hint' => 'View security alerts and audit logs.',
                    'permissions' => ['security.view', 'security.alerts.view', 'security.audit.view'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
            iconRef: 'tabler-shield-lock',
        );
    }
}
