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
                // ADR-638 (W-E2) : administration visuelle des presets organisationnels (groupe gouvernance).
                navItems: [
                    ['key' => 'organization-presets', 'title' => 'Organization Presets', 'to' => ['name' => 'platform-organization-presets'], 'icon' => 'tabler-sitemap', 'permission' => 'manage_jobdomains', 'group' => 'governance', 'sort' => 60],
                    // W-CONTRACT-3B (ADR-650) : catalogue des modèles de contrat.
                    ['key' => 'contract-templates', 'title' => 'Contract Templates', 'to' => ['name' => 'platform-contract-templates'], 'icon' => 'tabler-file-text', 'permission' => 'manage_jobdomains', 'group' => 'governance', 'sort' => 61],
                ],
                routeNames: ['platform-jobdomains', 'platform-jobdomains-id', 'platform-catalog-tab', 'platform-organization-presets', 'platform-organization-presets-id', 'platform-contract-templates'],
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
