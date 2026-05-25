<?php

namespace App\Modules\Workforce\Payroll;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

class WorkforcePayrollModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_payroll',
            name: 'Workforce Payroll',
            description: 'Payroll preparation, calculation, payslips, declarations, and export',
            surface: 'hr',
            sortOrder: 230,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-payroll', 'title' => 'Payroll', 'to' => ['name' => 'company-workforce-payroll'], 'icon' => 'tabler-receipt', 'permission' => 'workforce.payroll_prepare', 'surface' => 'hr', 'sort' => 50],
                    ['key' => 'workforce-dsn', 'title' => 'DSN / Compliance', 'to' => ['name' => 'company-workforce-dsn'], 'icon' => 'tabler-certificate', 'permission' => 'workforce.payroll_export', 'surface' => 'hr', 'sort' => 60],
                ],
                routeNames: [],
                middlewareKey: 'workforce_payroll',
            ),
            permissions: [
                ['key' => 'workforce.payroll_prepare', 'label' => 'Prepare Payroll', 'is_admin' => true, 'hint' => 'Prepare monthly payroll data.'],
                ['key' => 'workforce.payroll_validate', 'label' => 'Validate Payroll', 'is_admin' => true, 'hint' => 'Validate and lock payroll period.'],
                ['key' => 'workforce.payroll_export', 'label' => 'Export Payroll', 'is_admin' => true, 'hint' => 'Export payroll data to external systems.'],
            ],
            bundles: [
                [
                    'key' => 'workforce.payroll',
                    'label' => 'Payroll Management',
                    'hint' => 'Prepare, validate, and export payroll.',
                    'permissions' => ['workforce.payroll_prepare', 'workforce.payroll_validate', 'workforce.payroll_export', 'workforce.compensation_read'],
                    'is_admin' => true,
                ],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce', 'workforce_leave'],
        );
    }
}
