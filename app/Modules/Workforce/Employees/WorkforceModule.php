<?php

namespace App\Modules\Workforce\Employees;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforceModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce',
            name: 'Workforce',
            description: 'Employee management, contracts, compensation, and time tracking',
            surface: 'hr',
            sortOrder: 200,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-me', 'title' => 'My Space', 'to' => ['name' => 'company-workforce-me'], 'icon' => 'tabler-user-circle', 'surface' => 'hr', 'sort' => 5],
                    ['key' => 'workforce-employees', 'title' => 'Employees', 'to' => ['name' => 'company-workforce-employees'], 'icon' => 'tabler-users-group', 'permission' => 'workforce.view', 'surface' => 'hr', 'sort' => 10],
                    ['key' => 'workforce-time', 'title' => 'Time Tracking', 'to' => ['name' => 'company-workforce-time'], 'icon' => 'tabler-clock', 'permission' => 'workforce.view', 'surface' => 'hr', 'sort' => 20],
                    ['key' => 'workforce-organization', 'title' => 'Organization', 'to' => ['name' => 'company-workforce-organization-tab', 'params' => ['tab' => 'departments']], 'icon' => 'tabler-hierarchy-2', 'permission' => 'workforce.admin', 'surface' => 'hr', 'sort' => 80],
                ],
                routeNames: [],
                middlewareKey: 'workforce',
            ),
            permissions: [
                ['key' => 'workforce.view', 'label' => 'View Employees', 'hint' => 'View employee list and basic details.'],
                ['key' => 'workforce.manage', 'label' => 'Manage Employees', 'hint' => 'Create, edit, and manage employees.'],
                ['key' => 'workforce.contracts', 'label' => 'Manage Contracts', 'is_admin' => true, 'hint' => 'Create and manage employment contracts.'],
                ['key' => 'workforce.compensation_read', 'label' => 'View Compensation', 'is_admin' => true, 'hint' => 'View salary and compensation details.'],
                ['key' => 'workforce.compensation_manage', 'label' => 'Manage Compensation', 'is_admin' => true, 'hint' => 'Modify salary and compensation plans.'],
                ['key' => 'workforce.sensitive_read', 'label' => 'View Sensitive Data', 'is_admin' => true, 'hint' => 'Access SSN, IBAN, and other sensitive fields.'],
                ['key' => 'workforce.time_manage', 'label' => 'Manage Time Entries', 'hint' => 'Correct and manage time tracking entries.'],
                ['key' => 'workforce.time_approve', 'label' => 'Approve Timesheets', 'is_admin' => true, 'hint' => 'Validate and approve employee timesheets.'],
                ['key' => 'workforce.admin', 'label' => 'Workforce Administration', 'is_admin' => true, 'hint' => 'Full workforce administration access.'],
                ['key' => 'workforce.timesheet_view', 'label' => 'View Timesheets', 'hint' => 'View own timesheets.'],
                ['key' => 'workforce.timesheet_submit', 'label' => 'Submit Timesheets', 'hint' => 'Submit timesheets for approval.'],
                ['key' => 'workforce.timesheet_approve', 'label' => 'Approve Timesheets', 'is_admin' => true, 'hint' => 'Approve or reject submitted timesheets.'],
            ],
            bundles: [
                [
                    'key' => 'workforce.team',
                    'label' => 'Team Management',
                    'hint' => 'View and manage employees, manage time entries.',
                    'permissions' => ['workforce.view', 'workforce.manage', 'workforce.time_manage'],
                ],
                [
                    'key' => 'workforce.hr_management',
                    'label' => 'HR Management',
                    'hint' => 'Full HR access: contracts, compensation, sensitive data.',
                    'permissions' => ['workforce.contracts', 'workforce.compensation_read', 'workforce.compensation_manage', 'workforce.sensitive_read', 'workforce.admin'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'workforce.time_validation',
                    'label' => 'Time Validation',
                    'hint' => 'Manage and approve time entries and timesheets.',
                    'permissions' => ['workforce.time_manage', 'workforce.time_approve'],
                    'is_admin' => true,
                ],
                [
                    'key' => 'workforce.timesheet_employee',
                    'label' => 'Timesheet (Employee)',
                    'hint' => 'View and submit own timesheets.',
                    'permissions' => ['workforce.timesheet_view', 'workforce.timesheet_submit'],
                ],
                [
                    'key' => 'workforce.timesheet_management',
                    'label' => 'Timesheet Management',
                    'hint' => 'Approve and manage timesheets.',
                    'permissions' => ['workforce.timesheet_view', 'workforce.timesheet_submit', 'workforce.timesheet_approve', 'workforce.time_approve'],
                ],
            ],
            scope: 'company',
            type: 'addon',
        );
    }
}
