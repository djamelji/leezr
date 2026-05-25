<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\JobRole;

class UpdateJobRoleUseCase
{
    public function execute(JobRole $jobRole, array $data): JobRole
    {
        $jobRole->update($data);

        app(AuditLogger::class)->logCompany(
            companyId: $jobRole->company_id,
            action: 'job_role.updated',
            targetType: 'job_role',
            targetId: (string) $jobRole->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.organization',
                    'description' => "Job role '{$jobRole->title}' updated",
                ],
            ],
        );

        return $jobRole->fresh();
    }
}
