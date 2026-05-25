<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\JobRole;

class CreateJobRoleUseCase
{
    public function execute(
        int $companyId,
        string $title,
        ?int $departmentId = null,
        ?string $level = null,
        ?string $description = null,
        ?int $defaultHourlyRateCents = null,
    ): JobRole {
        $jobRole = JobRole::create([
            'company_id' => $companyId,
            'department_id' => $departmentId,
            'title' => $title,
            'level' => $level,
            'description' => $description,
            'default_hourly_rate_cents' => $defaultHourlyRateCents,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'job_role.created',
            targetType: 'job_role',
            targetId: (string) $jobRole->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.organization',
                    'description' => "Job role '{$title}' created",
                ],
            ],
        );

        return $jobRole;
    }
}
