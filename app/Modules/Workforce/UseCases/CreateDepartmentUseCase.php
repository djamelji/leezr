<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Department;

class CreateDepartmentUseCase
{
    public function execute(
        int $companyId,
        string $name,
        ?int $parentId = null,
        ?int $managerId = null,
        int $sortOrder = 0,
    ): Department {
        $department = Department::create([
            'company_id' => $companyId,
            'name' => $name,
            'parent_id' => $parentId,
            'manager_id' => $managerId,
            'sort_order' => $sortOrder,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'department.created',
            targetType: 'department',
            targetId: (string) $department->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.organization',
                    'description' => "Department '{$name}' created",
                ],
            ],
        );

        return $department;
    }
}
