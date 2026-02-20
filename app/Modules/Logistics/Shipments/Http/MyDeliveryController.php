<?php

namespace App\Modules\Logistics\Shipments\Http;

use App\Core\Models\Shipment;
use App\Modules\Logistics\Shipments\Http\Requests\ChangeShipmentStatusRequest;
use App\Modules\Logistics\Shipments\ReadModels\MyDeliveryReadModel;
use App\Modules\Logistics\Shipments\UseCases\ChangeShipmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyDeliveryController
{
    public function index(Request $request): JsonResponse
    {
        $deliveries = MyDeliveryReadModel::list(
            company: $request->attributes->get('company'),
            userId: $request->user()->id,
            status: $request->input('status'),
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($deliveries);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $delivery = MyDeliveryReadModel::find(
            company: $request->attributes->get('company'),
            userId: $request->user()->id,
            id: $id,
        );

        return response()->json([
            'shipment' => $delivery,
        ]);
    }

    public function updateStatus(ChangeShipmentStatusRequest $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        // Verify assignment: shipment must be assigned to the current user
        $shipment = Shipment::where('company_id', $company->id)
            ->where('assigned_to_user_id', $request->user()->id)
            ->findOrFail($id);

        try {
            $shipment = ChangeShipmentStatus::handle(
                company: $company,
                shipmentId: $shipment->id,
                newStatus: $request->input('status'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Delivery status updated.',
            'shipment' => $shipment,
        ]);
    }
}
