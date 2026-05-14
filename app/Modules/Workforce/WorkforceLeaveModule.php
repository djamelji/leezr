<?php

namespace App\Modules\Workforce;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforceLeaveModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_leave',
            name: 'Workforce Leave',
            description: 'Leave requests, ledger, balances, and accrual',
            surface: 'hr',
            sortOrder: 220,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-leave', 'title' => 'Leave', 'to' => ['name' => 'company-workforce-leave'], 'icon' => 'tabler-beach', 'permission' => 'workforce.leave_request', 'surface' => 'hr', 'sort' => 40],
                ],
                routeNames: [],
                middlewareKey: 'workforce_leave',
            ),
            permissions: [
                ['key' => 'workforce.leave_request', 'label' => 'Request Leave', 'hint' => 'Submit leave requests.'],
                ['key' => 'workforce.leave_approve', 'label' => 'Approve Leave', 'is_admin' => true, 'hint' => 'Approve or reject leave requests.'],
                ['key' => 'workforce.leave_view_all', 'label' => 'View All Leave', 'is_admin' => true, 'hint' => 'View all employee leave requests and balances.'],
                ['key' => 'workforce.leave_manage', 'label' => 'Manage Leave Types', 'is_admin' => true, 'hint' => 'Create, edit, and disable leave types.'],
                ['key' => 'workforce.leave_adjust', 'label' => 'Adjust Balances', 'is_admin' => true, 'hint' => 'Manually adjust leave balances via ledger.'],
            ],
            bundles: [
                [
                    'key' => 'workforce.leave_employee',
                    'label' => 'Leave (Employee)',
                    'hint' => 'Request leave and view own balances.',
                    'permissions' => ['workforce.leave_request'],
                ],
                [
                    'key' => 'workforce.leave_management',
                    'label' => 'Leave Management',
                    'hint' => 'Approve requests, view all balances, manage leave types.',
                    'permissions' => ['workforce.leave_request', 'workforce.leave_approve', 'workforce.leave_view_all', 'workforce.leave_manage'],
                ],
                [
                    'key' => 'workforce.leave_adjustment',
                    'label' => 'Leave Adjustment',
                    'hint' => 'Manually adjust employee leave balances.',
                    'permissions' => ['workforce.leave_adjust'],
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce'],
        );
    }
}
