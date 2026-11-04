<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Department;
use App\Core\Workforce\WorkLocation;

/**
 * Met à jour un besoin de personnel (Smart Planning SP1a, ADR-705).
 */
class UpdateCoverageRequirementUseCase
{
    public function execute(CoverageRequirement $req, array $data): CoverageRequirement
    {
        if (array_key_exists('department_id', $data) && ! empty($data['department_id'])) {
            $ok = Department::withoutGlobalScopes()->where('id', $data['department_id'])->where('company_id', $req->company_id)->exists();
            if (! $ok) {
                throw new \DomainException('Department does not belong to this company.');
            }
        }
        if (array_key_exists('work_location_id', $data) && ! empty($data['work_location_id'])) {
            $ok = WorkLocation::withoutGlobalScopes()->where('id', $data['work_location_id'])->where('company_id', $req->company_id)->exists();
            if (! $ok) {
                throw new \DomainException('Work location does not belong to this company.');
            }
        }

        $req->fill(collect($data)->only([
            'label', 'date', 'day_of_week', 'start_time', 'end_time',
            'department_id', 'work_location_id', 'activity_type',
            'headcount', 'priority', 'enabled', 'metadata',
        ])->toArray());
        $req->save();

        app(AuditLogger::class)->logCompany(
            companyId: $req->company_id,
            action: 'coverage_requirement.updated',
            targetType: 'coverage_requirement',
            targetId: (string) $req->id,
            options: ['metadata' => ['category' => 'workforce.planning']],
        );

        return $req;
    }
}
