<?php

namespace App\Modules\Logistics\Fleet;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class FleetModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'logistics_fleet',
            name: 'Fleet Management',
            description: 'Vehicle fleet management and driver assignment',
            surface: 'operations',
            sortOrder: 120,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
            ),
            permissions: [],
            bundles: [],
            type: 'addon',
            minPlan: 'pro',
            compatibleJobdomains: ['logistique'],
        );
    }
}
