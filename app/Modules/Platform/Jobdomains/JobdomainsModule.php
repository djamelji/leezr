<?php

namespace App\Modules\Platform\Jobdomains;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class JobdomainsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.jobdomains',
            name: 'Job Domains',
            description: 'Manage job domain templates and role bundles',
            surface: 'structure',
            sortOrder: 50,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'jobdomains', 'title' => 'Job Domains', 'to' => ['name' => 'platform-jobdomains'], 'icon' => 'tabler-briefcase', 'permission' => 'manage_jobdomains'],
                ],
                routeNames: ['platform-jobdomains'],
            ),
            permissions: [
                ['key' => 'manage_jobdomains', 'label' => 'Manage Job Domains'],
            ],
            bundles: [
                [
                    'key' => 'jobdomains.catalog',
                    'label' => 'Job Domain Catalog',
                    'hint' => 'Configure job domain templates and permission bundles.',
                    'permissions' => ['manage_jobdomains'],
                ],
            ],
            scope: 'platform',
            type: 'internal',
        );
    }
}
