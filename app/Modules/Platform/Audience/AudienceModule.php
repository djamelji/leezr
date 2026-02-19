<?php

namespace App\Modules\Platform\Audience;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class AudienceModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.audience',
            name: 'Audience',
            description: 'Mailing lists, subscribers, and subscription management',
            surface: 'structure',
            sortOrder: 85,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
            ),
            permissions: [
                ['key' => 'manage_audience', 'label' => 'Manage Audience'],
            ],
            bundles: [],
            scope: 'platform',
            type: 'internal',
        );
    }
}
