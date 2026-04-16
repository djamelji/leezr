<?php

namespace App\Modules\Core\Automations;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkflowsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.workflows',
            name: 'Workflows',
            description: 'User-defined automation rules with trigger → conditions → actions.',
            surface: 'structure',
            sortOrder: 18,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workflows', 'title' => 'Workflows', 'to' => ['name' => 'company-workflows'], 'icon' => 'tabler-automation', 'permission' => 'automations.view', 'surface' => 'structure'],
                ],
                routeNames: ['company-workflows', 'company-workflows-id'],
                middlewareKey: 'core.workflows',
            ),
            permissions: [
                ['key' => 'automations.view', 'label' => 'View workflow rules'],
                ['key' => 'automations.manage', 'label' => 'Create, edit, and delete workflow rules'],
            ],
            bundles: [
                [
                    'key' => 'automations.access',
                    'label' => 'Workflow Automation',
                    'permissions' => ['automations.view', 'automations.manage'],
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
