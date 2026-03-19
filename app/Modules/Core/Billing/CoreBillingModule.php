<?php

namespace App\Modules\Core\Billing;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class CoreBillingModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'core.billing',
            name: 'Plan & Billing',
            description: 'View and change company plan',
            surface: 'structure',
            sortOrder: 25,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'billing', 'title' => 'Billing', 'to' => ['name' => 'company-billing-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-receipt', 'permission' => 'billing.manage', 'surface' => 'structure'],
                ],
                routeNames: ['company-billing-tab'],
                middlewareKey: 'core.billing',
            ),
            permissions: [
                ['key' => 'billing.manage', 'label' => 'Manage Billing', 'is_admin' => true, 'hint' => 'Change company plan and manage billing.'],
            ],
            bundles: [
                [
                    'key' => 'billing.management',
                    'label' => 'Plan & Billing',
                    'hint' => 'Change company plan and manage billing.',
                    'permissions' => ['billing.manage'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'core',
        );
    }
}
