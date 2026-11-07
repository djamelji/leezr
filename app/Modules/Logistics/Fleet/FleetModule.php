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
            scope: 'company',
            type: 'addon',
            requires: ['logistics_shipments'],
            minPlan: 'pro',
            compatibleJobdomains: ['logistique'],
            // ADR-732 (P0-A) : déclaré mais NON livré (aucune capacité) → « En préparation », jamais vendable.
            commercialStatus: 'preview',
        );
    }
}
