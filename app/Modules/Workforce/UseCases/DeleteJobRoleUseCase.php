<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\JobRole;

class DeleteJobRoleUseCase
{
    public function execute(JobRole $jobRole): void
    {
        if ($jobRole->employees()->exists()) {
            throw new \DomainException('Cannot delete job role with assigned employees');
        }

        $title = $jobRole->title;
        $companyId = $jobRole->company_id;
        $id = $jobRole->id;

        $jobRole->delete();

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'job_role.deleted',
            targetType: 'job_role',
            targetId: (string) $id,
            options: [
                'metadata' => [
                    'category' => 'workforce.organization',
                    'description' => "Job role '{$title}' deleted",
                ],
            ],
        );
    }
}
