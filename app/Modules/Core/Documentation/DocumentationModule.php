<?php

namespace App\Modules\Core\Documentation;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class DocumentationModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.documentation',
            name: 'Documentation',
            description: 'Company-facing knowledge base and help articles.',
            surface: 'core',
            sortOrder: 8,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: [],
                middlewareKey: 'core.documentation',
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
                ['key' => 'documentation.view', 'label' => 'View documentation'],
            ],
            bundles: [
                [
                    'key' => 'documentation.access',
                    'label' => 'Documentation Access',
                    'permissions' => ['documentation.view'],
                    'unwired' => true, // ADR-806 : the help center is open to every member ; no route checks it (ADR-800)
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
