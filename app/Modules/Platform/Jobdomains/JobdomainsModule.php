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
                    // ADR-802 : plus d'entrée de menu « Presets d'organisation » — l'éditeur dédié reste ouvert depuis
                    // JobDomain → onglet Organisation et depuis le catalogue (un seul point d'entrée : le JobDomain).
                    // W-CONTRACT-3B (ADR-650) : catalogue des modèles de contrat.
                    ['key' => 'contract-templates', 'title' => 'Contract Templates', 'to' => ['name' => 'platform-contract-templates'], 'icon' => 'tabler-file-text', 'permission' => 'manage_jobdomains', 'group' => 'governance', 'sort' => 61],
                ],
                routeNames: ['platform-jobdomains-id', 'platform-catalog-tab', 'platform-organization-presets', 'platform-organization-presets-id', 'platform-contract-templates'],
            ),
            permissions: [
                ['key' => 'manage_jobdomains', 'label' => 'Manage Job Domains'],
                // ADR-798 — droits fins sur les presets versionnés (manage_jobdomains reste le droit complet)
                ['key' => 'presets.view', 'label' => 'View versioned presets'],
                ['key' => 'presets.edit', 'label' => 'Edit preset drafts'],
                ['key' => 'presets.publish', 'label' => 'Publish or archive preset versions'],
                ['key' => 'presets.preview_company', 'label' => 'Preview a preset on a company (read only)'],
            ],
            bundles: [
                [
                    'key' => 'jobdomains.catalog',
                    'label' => 'Job Domain Catalog',
                    'hint' => 'Configure job domain templates and permission bundles.',
                    'permissions' => ['manage_jobdomains'],
                ],
                [
                    'key' => 'presets.reader',
                    'label' => 'Preset Reader',
                    'hint' => 'View versioned presets and preview them on a company (read only).',
                    'permissions' => ['presets.view', 'presets.preview_company'],
                ],
                [
                    'key' => 'presets.editor',
                    'label' => 'Preset Editor',
                    'hint' => 'Edit preset drafts and create new versions.',
                    'permissions' => ['presets.view', 'presets.edit'],
                ],
                [
                    'key' => 'presets.publisher',
                    'label' => 'Preset Publisher',
                    'hint' => 'Publish or archive preset versions.',
                    'permissions' => ['presets.view', 'presets.publish'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
