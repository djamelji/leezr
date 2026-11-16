<?php

namespace App\Modules\Logistics\Shipments\UseCases;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChangeShipmentStatus
{
    /**
     * Transition a shipment to a new status.
     *
     * ADR-807 : `$assignedToUserId` = resource rule of the « my deliveries » scope. The assignment is checked
     * on the LOCKED row, in the same transaction as the update : a reassignment between the check and the
     * write cannot let the former driver change the shipment. null = collective scope (shipments.view).
     *
     * @throws \InvalidArgumentException if transition is not allowed
     */
    public static function handle(Company $company, int $shipmentId, string $newStatus, ?int $assignedToUserId = null): Shipment
    {
        return DB::transaction(fn () => self::apply($company, $shipmentId, $newStatus, $assignedToUserId));
    }

    private static function apply(Company $company, int $shipmentId, string $newStatus, ?int $assignedToUserId): Shipment
    {
        $shipment = Shipment::where('company_id', $company->id)
            ->when($assignedToUserId !== null, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->lockForUpdate()
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
