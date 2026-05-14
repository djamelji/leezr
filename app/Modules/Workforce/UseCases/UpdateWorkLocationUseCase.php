<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\WorkLocation;

class UpdateWorkLocationUseCase
{
    public function execute(WorkLocation $location, array $data): WorkLocation
    {
        if (isset($data['type']) && ! in_array($data['type'], WorkLocation::TYPES, true)) {
            throw new \DomainException("Invalid location type '{$data['type']}'. Allowed: " . implode(', ', WorkLocation::TYPES));
        }

        if (isset($data['name']) && $data['name'] !== $location->name) {
            $exists = WorkLocation::withoutGlobalScopes()
                ->where('company_id', $location->company_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $location->id)
                ->exists();

            if ($exists) {
                throw new \DomainException("A work location named '{$data['name']}' already exists.");
            }
        }

        $before = $location->only(['name', 'type', 'address', 'timezone', 'enabled']);

        $location->fill(collect($data)->only([
            'name', 'type', 'client_name', 'client_reference',
            'address', 'timezone', 'enabled', 'sort_order', 'metadata',
        ])->toArray());

        $location->save();

        app(AuditLogger::class)->logCompany(
            companyId: $location->company_id,
            action: 'work_location.updated',
            targetType: 'work_location',
            targetId: (string) $location->id,
            options: [
                'diffBefore' => $before,
                'diffAfter' => $location->only(['name', 'type', 'address', 'timezone', 'enabled']),
                'metadata' => ['category' => 'workforce.planning'],
            ],
        );

        return $location;
    }
}
