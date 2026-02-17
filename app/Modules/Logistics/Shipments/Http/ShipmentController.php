<?php

namespace App\Modules\Logistics\Shipments\Http;

use App\Modules\Logistics\Shipments\Http\Requests\ChangeShipmentStatusRequest;
use App\Modules\Logistics\Shipments\Http\Requests\StoreShipmentRequest;
use App\Modules\Logistics\Shipments\ReadModels\ShipmentReadModel;
use App\Modules\Logistics\Shipments\UseCases\ChangeShipmentStatus;
use App\Modules\Logistics\Shipments\UseCases\CreateShipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController
{
    public function index(Request $request): JsonResponse
    {
        $shipments = ShipmentReadModel::list(
            company: $request->attributes->get('company'),
            status: $request->input('status'),
            search: $request->input('search'),
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($shipments);
    }

    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $shipment = CreateShipment::handle(
            company: $request->attributes->get('company'),
            user: $request->user(),
            data: $request->validated(),
        );

        return response()->json([
            'message' => 'Shipment created.',
            'shipment' => $shipment,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $shipment = ShipmentReadModel::find(
            company: $request->attributes->get('company'),
            id: $id,
        );

        return response()->json([
            'shipment' => $shipment,
        ]);
    }

    public function changeStatus(ChangeShipmentStatusRequest $request, int $id): JsonResponse
    {
        try {
            $shipment = ChangeShipmentStatus::handle(
                company: $request->attributes->get('company'),
                shipmentId: $id,
                newStatus: $request->input('status'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Shipment status updated.',
            'shipment' => $shipment,
        ]);
    }
}
