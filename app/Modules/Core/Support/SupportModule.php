<?php

namespace App\Modules\Core\Support;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class SupportModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.support',
            name: 'Support',
            description: 'Contact platform support via tickets.',
            surface: 'core',
            sortOrder: 7,
            capabilities: new Capabilities(
                navItems: [],
                routeNames: ['company-support', 'company-support-id'],
                middlewareKey: 'core.support',
                footerLinks: [
                    [
                        'key' => 'footer-support',
                        'label' => 'footer.support',
                        'to' => ['name' => 'company-support'],
                        'icon' => 'tabler-message-circle-cog',
                        'permission' => 'support.view',
                        'sortOrder' => 10,
                    ],
                ],
            ),
            permissions: [
                ['key' => 'support.view', 'label' => 'View support tickets'],
                ['key' => 'support.create', 'label' => 'Create support tickets'],
            ],
            bundles: [
                [
                    'key' => 'support.access',
                    'label' => 'Support Access',
                    'permissions' => ['support.view', 'support.create'],
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
