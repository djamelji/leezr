<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Department;

class DeleteDepartmentUseCase
{
    public function execute(Department $department): void
    {
        if ($department->employees()->exists()) {
            throw new \DomainException('Cannot delete department with assigned employees');
        }

        if ($department->children()->exists()) {
            throw new \DomainException('Cannot delete department with sub-departments');
        }

        $name = $department->name;
        $companyId = $department->company_id;
        $id = $department->id;

        $department->delete();

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'department.deleted',
            targetType: 'department',
            targetId: (string) $id,
            options: [
                'metadata' => [
                    'category' => 'workforce.organization',
                    'description' => "Department '{$name}' deleted",
                ],
            ],
        );
    }
}
