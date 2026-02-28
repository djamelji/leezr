<?php

namespace App\Modules\Core\Theme;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class ThemeModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.theme',
            name: 'Theme',
            description: 'Light / Dark / System theme control',
            surface: 'structure',
            sortOrder: 5,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
                middlewareKey: 'core.theme',
            ),
            permissions: [
                ['key' => 'theme.view', 'label' => 'View Theme', 'hint' => 'See the theme toggle in the header.'],
                ['key' => 'theme.manage', 'label' => 'Manage Theme', 'hint' => 'Change personal theme preference (light / dark / system).'],
            ],
            bundles: [
                [
                    'key' => 'theme.full',
                    'label' => 'Theme Control',
                    'hint' => 'View and change theme preference.',
                    'permissions' => ['theme.view', 'theme.manage'],
                ],
                [
                    'key' => 'theme.readonly',
                    'label' => 'Theme View Only',
                    'hint' => 'See the current theme but cannot change it.',
                    'permissions' => ['theme.view'],
                ],
            ],
            scope: 'company',
            type: 'core',
            iconRef: 'tabler-moon',
        );
    }
}
