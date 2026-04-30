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
                navItems: [],
                routeNames: ['platform-documentation', 'platform-documentation-slug', 'platform-communications-tab'],
                footerLinks: [
                    [
                        'key' => 'footer-help-center',
                        'label' => 'footer.helpCenter',
                        'href' => '/help-center',
                        'icon' => 'tabler-lifebuoy',
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
