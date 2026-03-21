<?php

namespace App\Modules\Platform\Audit;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class AuditModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.audit',
            name: 'Audit Logs',
            description: 'Platform and company audit trail — append-only log of all mutations',
            surface: 'structure',
            sortOrder: 93,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: ['platform-access-tab', 'platform-supervision-tab'],
            ),
            permissions: [
                ['key' => 'view_audit_logs', 'label' => 'View Audit Logs'],
            ],
            bundles: [
                [
                    'key' => 'audit.view',
                    'label' => 'Audit Log Viewer',
                    'hint' => 'Browse platform-wide audit trail of all administrative actions.',
                    'permissions' => ['view_audit_logs'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
