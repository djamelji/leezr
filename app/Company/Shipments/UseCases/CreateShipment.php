<?php

namespace App\Company\Shipments\UseCases;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;

class CreateShipment
{
    /**
     * Create a new shipment in draft status.
     */
    public static function handle(Company $company, User $user, array $data): Shipment
    {
        $shipment = Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $user->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => $data['origin_address'] ?? null,
            'destination_address' => $data['destination_address'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $shipment->load('createdBy:id,name');

        return $shipment;
    }
}
