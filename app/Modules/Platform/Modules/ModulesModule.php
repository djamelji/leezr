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
                    ['key' => 'modules', 'title' => 'Modules', 'to' => ['name' => 'platform-modules'], 'icon' => 'tabler-puzzle', 'permission' => 'manage_modules'],
                ],
                routeNames: ['platform-modules'],
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
            scope: 'platform',
            type: 'internal',
        );
    }
}
