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
                // ADR-598 LOT B: surfaced as a child of the Governance → Catalogue group.
                navItems: [
                    ['key' => 'jobdomains', 'title' => 'Job Domains', 'to' => ['name' => 'platform-catalog-tab', 'params' => ['tab' => 'jobdomains']], 'icon' => 'tabler-briefcase', 'permission' => 'manage_jobdomains', 'parent' => 'catalog', 'sort' => 30],
                ],
                routeNames: ['platform-jobdomains', 'platform-jobdomains-id', 'platform-catalog-tab'],
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
            scope: 'admin',
            type: 'internal',
        );
    }
}
