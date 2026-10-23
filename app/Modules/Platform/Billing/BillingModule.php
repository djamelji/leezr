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
                    ['key' => 'platform-billing', 'title' => 'Billing', 'to' => ['name' => 'platform-billing-tab', 'params' => ['tab' => 'overview']], 'icon' => 'tabler-file-invoice', 'permission' => 'view_billing', 'group' => 'finance', 'sort' => 20],
                ],
                routeNames: ['platform-billing-tab', 'platform-billing-invoices-id'],
            ),
            // ADR-597 (LOT 3): removed dead permissions (manage_billing_providers,
            // manage_billing_policies, view_billing_audit) — no route ever checked them; all billing
            // writes are gated by manage_billing and reads by view_billing.
            permissions: [
                ['key' => 'view_billing', 'label' => 'View Billing'],
                ['key' => 'manage_billing', 'label' => 'Manage Billing'],
            ],
            bundles: [
                [
                    'key' => 'billing.full',
                    'label' => 'Full Billing Management',
                    'hint' => 'Complete billing governance access.',
                    'permissions' => ['view_billing', 'manage_billing'],
                ],
                [
                    'key' => 'billing.readonly',
                    'label' => 'Billing Read-Only',
                    'hint' => 'View billing data.',
                    'permissions' => ['view_billing'],
                ],
            ],
            scope: 'admin',
            type: 'platform',
            visibility: 'visible',
            iconRef: 'tabler-credit-card',
            settingsRoute: null,
        );
    }
}
