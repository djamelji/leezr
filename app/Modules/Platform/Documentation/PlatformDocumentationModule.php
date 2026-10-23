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
            // ADR-597 (LOT 3): removed dead permission view_documentation — all documentation
            // routes are gated by manage_documentation.
            permissions: [
                ['key' => 'manage_documentation', 'label' => 'Manage Documentation'],
            ],
            bundles: [
                [
                    'key' => 'documentation.admin',
                    'label' => 'Documentation Administration',
                    'permissions' => ['manage_documentation'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
        );
    }
}
