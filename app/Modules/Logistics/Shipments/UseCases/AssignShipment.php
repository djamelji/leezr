<?php

namespace App\Modules\Logistics\Shipments\UseCases;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\Shipment;

class AssignShipment
{
    /**
     * Assign (or unassign) a shipment to a company member.
     *
     * @throws \InvalidArgumentException if user is not a member of the company
     */
    public static function handle(Company $company, int $shipmentId, ?int $userId): Shipment
    {
        $shipment = Shipment::where('company_id', $company->id)
            ->findOrFail($shipmentId);

        if ($userId !== null) {
            $isMember = Membership::where('company_id', $company->id)
                ->where('user_id', $userId)
                ->exists();

            if (!$isMember) {
                throw new \InvalidArgumentException('User is not a member of this company.');
            }
        }

        $shipment->update(['assigned_to_user_id' => $userId]);
        $shipment->load('assignedTo:id,first_name,last_name', 'createdBy:id,first_name,last_name');

        return $shipment;
    }
}
