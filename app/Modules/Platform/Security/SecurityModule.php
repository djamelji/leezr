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
                // ADR-598 LOT F: Security surfaced as an independent child of System → Operations.
                navItems: [
                    ['key' => 'ops-security', 'title' => 'Security', 'to' => ['name' => 'platform-operations-tab', 'params' => ['tab' => 'security']], 'icon' => 'tabler-shield-lock', 'permission' => 'security.alerts.view', 'parent' => 'operations', 'sort' => 25],
                ],
                routeNames: ['platform-operations-tab'],
            ),
            // ADR-597 (LOT 3): removed dead permissions (security.view, security.manage,
            // security.audit.view) — only security.alerts.* are enforced on routes.
            permissions: [
                ['key' => 'security.alerts.view', 'label' => 'View Security Alerts'],
                ['key' => 'security.alerts.manage', 'label' => 'Manage Security Alerts'],
            ],
            bundles: [
                [
                    'key' => 'security.full',
                    'label' => 'Full Security Management',
                    'hint' => 'View and manage security alerts.',
                    'permissions' => ['security.alerts.view', 'security.alerts.manage'],
                ],
                [
                    'key' => 'security.readonly',
                    'label' => 'Security Read-Only',
                    'hint' => 'View security alerts.',
                    'permissions' => ['security.alerts.view'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
            iconRef: 'tabler-shield-lock',
        );
    }
}
