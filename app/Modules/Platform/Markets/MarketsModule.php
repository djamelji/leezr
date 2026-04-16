<?php

namespace App\Modules\Platform\Markets;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class MarketsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.markets',
            name: 'Markets',
            description: 'International market governance — markets, legal statuses, languages, FX rates',
            surface: 'structure',
            sortOrder: 12,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'platform-international', 'title' => 'International', 'to' => ['name' => 'platform-international-tab', 'params' => ['tab' => 'markets']], 'icon' => 'tabler-world', 'permission' => 'manage_markets', 'group' => 'international', 'sort' => 50],
                ],
                routeNames: ['platform-international-tab', 'platform-markets-key'],
            ),
            permissions: [
                ['key' => 'manage_markets', 'label' => 'Manage markets, languages, legal statuses & FX rates'],
            ],
            bundles: [
                [
                    'key' => 'markets.governance',
                    'label' => 'Market Governance',
                    'hint' => 'Manage markets, languages, legal statuses & FX rates.',
                    'permissions' => ['manage_markets'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
            visibility: 'visible',
        );
    }
}
