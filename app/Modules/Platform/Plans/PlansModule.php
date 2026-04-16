<?php

namespace App\Modules\Platform\Plans;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class PlansModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.plans',
            name: 'Plans',
            description: 'Plan catalog and commercial governance',
            surface: 'structure',
            sortOrder: 15,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'plans', 'title' => 'Plans', 'to' => ['name' => 'platform-plans'], 'icon' => 'tabler-chart-bar', 'permission' => 'manage_plans', 'group' => 'finance', 'sort' => 21],
                ],
                routeNames: ['platform-plans', 'platform-plans-key'],
            ),
            permissions: [
                ['key' => 'manage_plans', 'label' => 'Manage Plans'],
            ],
            bundles: [
                [
                    'key' => 'plans.catalog',
                    'label' => 'Plan Catalog',
                    'hint' => 'Configure plan definitions and commercial governance.',
                    'permissions' => ['manage_plans'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
            visibility: 'visible',
        );
    }
}
