<?php

namespace App\Modules\Logistics\Shipments\Http;

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

        // ADR-807 : the assignment rule is enforced by the use case on the locked row (no check-then-act).
        try {
            $shipment = ChangeShipmentStatus::handle(
                company: $company,
                shipmentId: $id,
                newStatus: $request->input('status'),
                assignedToUserId: $request->user()->id,
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
