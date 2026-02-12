<?php

namespace App\Company\Shipments\UseCases;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use Illuminate\Support\Facades\Log;

class ChangeShipmentStatus
{
    /**
     * Transition a shipment to a new status.
     *
     * @throws \InvalidArgumentException if transition is not allowed
     */
    public static function handle(Company $company, int $shipmentId, string $newStatus): Shipment
    {
        $shipment = Shipment::where('company_id', $company->id)
            ->findOrFail($shipmentId);

        if (!$shipment->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$shipment->status}' to '{$newStatus}'."
            );
        }

        $oldStatus = $shipment->status;

        $shipment->update(['status' => $newStatus]);
        $shipment->load('createdBy:id,name');

        Log::info('shipment.status.changed', [
            'shipment_id' => $shipment->id,
            'company_id' => $company->id,
            'from' => $oldStatus,
            'to' => $newStatus,
        ]);

        return $shipment;
    }
}
