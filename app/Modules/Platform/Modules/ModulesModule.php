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
                    ['key' => 'catalog', 'title' => 'Catalog', 'to' => ['name' => 'platform-catalog-tab', 'params' => ['tab' => 'modules']], 'icon' => 'tabler-package', 'permission' => 'manage_modules', 'group' => 'clients', 'sort' => 30],
                ],
                routeNames: ['platform-modules', 'platform-modules-key', 'platform-catalog-tab'],
            ),
            permissions: [
                ['key' => 'manage_modules', 'label' => 'Manage Modules'],
            ],
            bundles: [
                [
                    'key' => 'modules.catalog',
                    'label' => 'Module Catalog',
                    'hint' => 'Toggle module availability for companies.',
                    'permissions' => ['manage_modules'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
