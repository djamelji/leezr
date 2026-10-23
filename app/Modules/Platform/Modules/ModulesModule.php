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
                    // ADR-598 LOT B: 'catalog' is a collapsible parent (no route) under Governance.
                    // Children (Modules, Plans, JobDomains) are contributed by their own modules.
                    ['key' => 'catalog', 'title' => 'Catalog', 'icon' => 'tabler-package', 'group' => 'governance', 'sort' => 10],
                    ['key' => 'catalog-modules', 'title' => 'Modules', 'to' => ['name' => 'platform-catalog-tab', 'params' => ['tab' => 'modules']], 'icon' => 'tabler-puzzle', 'permission' => 'manage_modules', 'parent' => 'catalog', 'sort' => 10],
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
