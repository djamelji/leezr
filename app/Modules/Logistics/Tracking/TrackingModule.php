<?php

namespace App\Modules\Logistics\Tracking;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class TrackingModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'logistics_tracking',
            name: 'Tracking',
            description: 'Real-time shipment tracking and notifications',
            surface: 'operations',
            sortOrder: 110,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
            ),
            permissions: [],
            bundles: [],
            type: 'addon',
            compatibleJobdomains: ['logistique'],
        );
    }
}
