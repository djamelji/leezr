<?php

namespace App\Modules\Platform\Billing;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class BillingModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'platform.billing',
            name: 'Billing',
            description: 'Billing and subscription management',
            surface: 'structure',
            sortOrder: 70,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'billing', 'title' => 'Billing', 'to' => ['name' => 'platform-billing'], 'icon' => 'tabler-credit-card', 'permission' => 'manage_billing'],
                ],
                routeNames: ['platform-billing'],
            ),
            permissions: [
                ['key' => 'manage_billing', 'label' => 'Manage Billing'],
                ['key' => 'view_billing', 'label' => 'View Billing'],
            ],
            bundles: [
                [
                    'key' => 'billing.management',
                    'label' => 'Billing Management',
                    'hint' => 'Manage billing and view subscription details.',
                    'permissions' => ['manage_billing', 'view_billing'],
                ],
            ],
            scope: 'platform',
            type: 'internal',
            visibility: 'hidden',
        );
    }
}
