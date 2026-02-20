<?php

namespace App\Modules\Logistics\Shipments;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class ShipmentsModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'logistics_shipments',
            name: 'Shipments',
            description: 'Manage logistics shipments with status workflow',
            surface: 'operations',
            sortOrder: 100,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'shipments', 'title' => 'Shipments', 'to' => ['name' => 'company-shipments'], 'icon' => 'tabler-truck', 'permission' => 'shipments.view', 'surface' => 'operations'],
                    ['key' => 'my-deliveries', 'title' => 'My Deliveries', 'to' => ['name' => 'company-my-deliveries'], 'icon' => 'tabler-truck-delivery', 'permission' => 'shipments.view_own', 'surface' => 'operations'],
                ],
                routeNames: ['company-shipments', 'company-shipments-create', 'company-shipments-id', 'company-my-deliveries', 'company-my-deliveries-id'],
                middlewareKey: 'logistics_shipments',
            ),
            permissions: [
                ['key' => 'shipments.view', 'label' => 'View Shipments', 'hint' => 'See the shipments list and details.'],
                ['key' => 'shipments.create', 'label' => 'Create Shipments', 'hint' => 'Add new shipments to the system.'],
                ['key' => 'shipments.manage_status', 'label' => 'Manage Shipment Status', 'hint' => 'Update shipment status and workflow.'],
                ['key' => 'shipments.assign', 'label' => 'Assign Shipments', 'hint' => 'Assign shipments to team members.'],
                ['key' => 'shipments.view_own', 'label' => 'View Own Deliveries', 'hint' => 'See only shipments assigned to you.'],
                ['key' => 'shipments.manage_fields', 'label' => 'Manage Shipment Fields', 'is_admin' => true, 'hint' => 'Configure custom fields on shipments.'],
                ['key' => 'shipments.delete', 'label' => 'Delete Shipments', 'is_admin' => true, 'hint' => 'Permanently remove shipments from the system.'],
            ],
            bundles: [
                [
                    'key' => 'shipments.operations',
                    'label' => 'Shipment Operations',
                    'hint' => 'View, create, manage status, and assign shipments.',
                    'permissions' => ['shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign'],
                ],
                [
                    'key' => 'shipments.administration',
                    'label' => 'Shipment Administration',
                    'hint' => 'Configure custom fields and delete shipments.',
                    'permissions' => ['shipments.manage_fields', 'shipments.delete'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'shipments.delivery',
                    'label' => 'Delivery Operations',
                    'hint' => 'View assigned shipments and update their status.',
                    'permissions' => ['shipments.view_own', 'shipments.manage_status'],
                ],
            ],
            type: 'addon',
        );
    }
}
