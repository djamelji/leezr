<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Department;
use App\Core\Workforce\WorkLocation;

/**
 * Crée un besoin de personnel (Smart Planning SP1a, ADR-705). Valide la portée côté serveur.
 */
class CreateCoverageRequirementUseCase
{
    public function execute(int $companyId, array $data): CoverageRequirement
    {
        $this->guardScope($companyId, $data);

        if (empty($data['date']) && ! isset($data['day_of_week'])) {
            throw new \DomainException('A coverage requirement needs either a date or a day_of_week.');
        }

        $req = CoverageRequirement::create([
            'company_id' => $companyId,
            'label' => $data['label'] ?? null,
            'date' => $data['date'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'work_location_id' => $data['work_location_id'] ?? null,
            'activity_type' => $data['activity_type'] ?? null,
            'coverage_category_id' => $data['coverage_category_id'] ?? null,
            'headcount' => $data['headcount'] ?? 1,
            'priority' => $data['priority'] ?? 'normal',
            'source' => $data['source'] ?? 'manual',
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'coverage_requirement.created',
            targetType: 'coverage_requirement',
            targetId: (string) $req->id,
            options: ['metadata' => ['category' => 'workforce.planning', 'headcount' => $req->headcount]],
        );

        return $req;
    }

    private function guardScope(int $companyId, array $data): void
    {
        if (! empty($data['department_id'])) {
            $ok = Department::withoutGlobalScopes()->where('id', $data['department_id'])->where('company_id', $companyId)->exists();
            if (! $ok) {
                throw new \DomainException('Department does not belong to this company.');
            }
        }
        if (! empty($data['work_location_id'])) {
            $ok = WorkLocation::withoutGlobalScopes()->where('id', $data['work_location_id'])->where('company_id', $companyId)->exists();
            if (! $ok) {
                throw new \DomainException('Work location does not belong to this company.');
            }
        }
    }
}
