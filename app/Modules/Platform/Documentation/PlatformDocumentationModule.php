<?php

namespace App\Modules\Platform\Documentation;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class PlatformDocumentationModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.documentation',
            name: 'Documentation',
            description: 'Manage knowledge base topics and articles.',
            surface: 'governance',
            sortOrder: 96,
            capabilities: new Capabilities(
                navItems: [
                    [
                        'key' => 'documentation',
                        'title' => 'Documentation',
                        'to' => ['name' => 'platform-documentation'],
                        'icon' => 'tabler-book',
                        'permission' => 'manage_documentation',
                        'group' => 'governance',
                    ],
                ],
                routeNames: ['platform-documentation', 'platform-documentation-slug'],
                footerLinks: [
                    [
                        'key' => 'footer-help-center',
                        'label' => 'footer.helpCenter',
                        'href' => '/help-center',
                        'icon' => 'tabler-help',
                        'permission' => '',
                        'sortOrder' => 20,
                    ],
                ],
            ),
            permissions: [
                ['key' => 'manage_documentation', 'label' => 'Manage Documentation'],
                ['key' => 'view_documentation', 'label' => 'View Documentation'],
            ],
            bundles: [
                [
                    'key' => 'documentation.admin',
                    'label' => 'Documentation Administration',
                    'permissions' => ['manage_documentation', 'view_documentation'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
