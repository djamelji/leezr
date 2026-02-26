<?php

namespace App\Modules\Core\Audit;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class AuditModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.audit',
            name: 'Audit Log',
            description: 'Company audit trail — append-only log of all mutations',
            surface: 'structure',
            sortOrder: 26,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'audit', 'title' => 'Audit Log', 'to' => ['name' => 'company-audit'], 'icon' => 'tabler-file-search', 'permission' => 'audit.view', 'surface' => 'structure'],
                ],
                routeNames: ['company-audit'],
                middlewareKey: 'core.audit',
            ),
            permissions: [
                ['key' => 'audit.view', 'label' => 'View Audit Log', 'is_admin' => true, 'hint' => 'Browse the company audit trail of all administrative actions.'],
            ],
            bundles: [
                [
                    'key' => 'audit.viewer',
                    'label' => 'Audit Log Viewer',
                    'hint' => 'View the audit trail of all administrative actions in this company.',
                    'permissions' => ['audit.view'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
