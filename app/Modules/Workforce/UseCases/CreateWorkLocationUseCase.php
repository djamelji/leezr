<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\WorkLocation;

class CreateWorkLocationUseCase
{
    public function execute(
        int $companyId,
        string $name,
        string $type = WorkLocation::TYPE_INTERNAL,
        ?string $clientName = null,
        ?string $clientReference = null,
        ?string $address = null,
        ?string $timezone = null,
    ): WorkLocation {
        if (! in_array($type, WorkLocation::TYPES, true)) {
            throw new \DomainException("Invalid location type '{$type}'. Allowed: " . implode(', ', WorkLocation::TYPES));
        }

        $exists = WorkLocation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new \DomainException("A work location named '{$name}' already exists.");
        }

        $location = WorkLocation::create([
            'company_id' => $companyId,
            'name' => $name,
            'type' => $type,
            'client_name' => $clientName,
            'client_reference' => $clientReference,
            'address' => $address,
            'timezone' => $timezone,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'work_location.created',
            targetType: 'work_location',
            targetId: (string) $location->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.planning',
                    'name' => $name,
                    'type' => $type,
                ],
            ],
        );

        return $location;
    }
}
