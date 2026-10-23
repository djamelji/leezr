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
                // ADR-598 LOT B: surfaced as a child of the Governance → Catalogue group.
                navItems: [
                    ['key' => 'plans', 'title' => 'Plans & Pricing', 'to' => ['name' => 'platform-catalog-tab', 'params' => ['tab' => 'plans']], 'icon' => 'tabler-receipt-2', 'permission' => 'manage_plans', 'parent' => 'catalog', 'sort' => 20],
                ],
                routeNames: ['platform-plans', 'platform-plans-key', 'platform-catalog-tab'],
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
