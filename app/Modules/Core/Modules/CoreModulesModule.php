<?php

namespace App\Modules\Core\Modules;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class CoreModulesModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.modules',
            name: 'Module Catalog',
            description: 'Browse and toggle company modules',
            surface: 'structure',
            sortOrder: 30,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'modules', 'title' => 'Modules', 'to' => ['name' => 'company-modules'], 'icon' => 'tabler-puzzle', 'permission' => 'modules.manage', 'surface' => 'structure'],
                ],
                routeNames: ['company-modules'],
                middlewareKey: 'core.modules',
            ),
            permissions: [
                ['key' => 'modules.manage', 'label' => 'Manage Modules', 'is_admin' => true, 'hint' => 'Enable or disable company modules.'],
            ],
            bundles: [
                [
                    'key' => 'modules.management',
                    'label' => 'Module Management',
                    'hint' => 'Enable or disable company modules.',
                    'permissions' => ['modules.manage'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
