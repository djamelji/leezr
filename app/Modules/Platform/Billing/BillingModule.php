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
            name: 'Payments',
            description: 'Payment modules, policies and subscription governance',
            surface: 'structure',
            sortOrder: 65,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'payments', 'title' => 'Payments', 'to' => ['name' => 'platform-payments'], 'icon' => 'tabler-credit-card', 'permission' => 'manage_billing'],
                    ['key' => 'billing', 'title' => 'Billing', 'to' => ['name' => 'platform-billing'], 'icon' => 'tabler-file-invoice', 'permission' => 'view_billing'],
                ],
                routeNames: ['platform-payments', 'platform-billing'],
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
            scope: 'admin',
            type: 'internal',
            visibility: 'visible',
        );
    }
}
