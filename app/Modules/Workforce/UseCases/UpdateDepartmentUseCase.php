<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Department;

class UpdateDepartmentUseCase
{
    public function execute(Department $department, array $data): Department
    {
        $department->update($data);

        app(AuditLogger::class)->logCompany(
            companyId: $department->company_id,
            action: 'department.updated',
            targetType: 'department',
            targetId: (string) $department->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.organization',
                    'description' => "Department '{$department->name}' updated",
                ],
            ],
        );

        return $department->fresh();
    }
}
