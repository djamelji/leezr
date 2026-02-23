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
            description: 'International market governance — markets, legal statuses, languages, translations',
            surface: 'structure',
            sortOrder: 12,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'markets', 'title' => 'Markets', 'to' => ['name' => 'platform-markets'], 'icon' => 'tabler-world', 'permission' => 'manage_markets'],
                    ['key' => 'languages', 'title' => 'Languages', 'to' => ['name' => 'platform-languages'], 'icon' => 'tabler-language', 'permission' => 'manage_markets'],
                ],
                routeNames: ['platform-markets', 'platform-languages'],
            ),
            permissions: [
                ['key' => 'manage_markets', 'label' => 'Manage markets, languages & translations'],
            ],
            bundles: [],
            scope: 'platform',
            type: 'internal',
            visibility: 'visible',
        );
    }
}
