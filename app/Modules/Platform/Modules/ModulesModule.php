<?php

namespace App\Modules\Platform\Modules;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class ModulesModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.modules',
            name: 'Modules',
            description: 'Manage module availability across companies',
            surface: 'structure',
            sortOrder: 40,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'catalog', 'title' => 'Catalog', 'to' => ['name' => 'platform-catalog-tab', 'params' => ['tab' => 'modules']], 'icon' => 'tabler-package', 'anyPermission' => ['manage_modules', 'view_modules'], 'group' => 'governance', 'sort' => 10],
                ],
                routeNames: ['platform-modules-key', 'platform-catalog-tab'],
            ),
            permissions: [
                ['key' => 'manage_modules', 'label' => 'Manage Modules'],
                ['key' => 'view_modules', 'label' => 'View the module catalog (read only)'], // ADR-863
            ],
            bundles: [
                [
                    'key' => 'modules.catalog',
                    'label' => 'Module Catalog',
                    'hint' => 'Toggle module availability for companies.',
                    'permissions' => ['manage_modules'],
                ],
                [
                    'key' => 'modules.catalog.reader', // ADR-863
                    'label' => 'Module Catalog (read only)',
                    'hint' => 'See the module catalog without changing it.',
                    'permissions' => ['view_modules'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
