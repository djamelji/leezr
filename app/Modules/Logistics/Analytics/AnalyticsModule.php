<?php

namespace App\Modules\Logistics\Analytics;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class AnalyticsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'logistics_analytics',
            name: 'Logistics Analytics',
            description: 'Advanced analytics and reporting for logistics operations',
            surface: 'operations',
            sortOrder: 130,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
            ),
            permissions: [],
            bundles: [],
            type: 'addon',
            minPlan: 'business',
            compatibleJobdomains: ['logistique'],
        );
    }
}
