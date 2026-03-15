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
                    ['key' => 'billing', 'title' => 'Billing', 'to' => ['name' => 'platform-billing'], 'icon' => 'tabler-file-invoice', 'permission' => 'view_billing', 'group' => 'governance'],
                ],
                routeNames: ['platform-billing', 'platform-billing-invoices-id', 'platform-billing-advanced-tab'],
            ),
            permissions: [
                ['key' => 'view_billing', 'label' => 'View Billing'],
                ['key' => 'manage_billing', 'label' => 'Manage Billing'],
                ['key' => 'manage_billing_providers', 'label' => 'Manage Payment Providers'],
                ['key' => 'manage_billing_policies', 'label' => 'Manage Billing Policies'],
                ['key' => 'view_billing_audit', 'label' => 'View Billing Audit'],
            ],
            bundles: [
                [
                    'key' => 'billing.full',
                    'label' => 'Full Billing Management',
                    'hint' => 'Complete billing governance access.',
                    'permissions' => ['view_billing', 'manage_billing', 'manage_billing_providers', 'manage_billing_policies', 'view_billing_audit'],
                ],
                [
                    'key' => 'billing.readonly',
                    'label' => 'Billing Read-Only',
                    'hint' => 'View billing data and audit logs.',
                    'permissions' => ['view_billing', 'view_billing_audit'],
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
