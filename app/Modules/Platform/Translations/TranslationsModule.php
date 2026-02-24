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
                routeNames: ['platform-international-tab'],
            ),
            permissions: [
                ['key' => 'manage_translations', 'label' => 'Manage translation bundles & market overrides'],
            ],
            bundles: [],
            scope: 'platform',
            type: 'internal',
            visibility: 'visible',
        );
    }
}
