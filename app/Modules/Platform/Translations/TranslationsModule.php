<?php

namespace App\Modules\Platform\Translations;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class TranslationsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.translations',
            name: 'Translations',
            description: 'Translation governance — matrix editor, bundles, market overrides, import/export',
            surface: 'structure',
            sortOrder: 13,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
            ),
            permissions: [
                ['key' => 'manage_translations', 'label' => 'Manage translation bundles & market overrides'],
            ],
            bundles: [
                [
                    'key' => 'translations.management',
                    'label' => 'Translation Management',
                    'hint' => 'Manage translation bundles & market overrides.',
                    'permissions' => ['manage_translations'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
            visibility: 'visible',
        );
    }
}
