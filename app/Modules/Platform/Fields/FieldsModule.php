<?php

namespace App\Modules\Platform\Fields;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class FieldsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.fields',
            name: 'Custom Fields',
            description: 'Manage field definitions and company activations',
            surface: 'structure',
            sortOrder: 60,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: ['platform-fields', 'platform-catalog-tab'],
            ),
            permissions: [
                ['key' => 'manage_field_definitions', 'label' => 'Manage Field Definitions'],
            ],
            bundles: [
                [
                    'key' => 'fields.catalog',
                    'label' => 'Field Catalog',
                    'hint' => 'Define and activate custom fields for companies.',
                    'permissions' => ['manage_field_definitions'],
                ],
            ],
            scope: 'admin',
            type: 'internal',
        );
    }
}
