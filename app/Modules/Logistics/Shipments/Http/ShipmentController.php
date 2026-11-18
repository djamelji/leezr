<?php

namespace App\Modules\Logistics\Shipments\Http;

use App\Modules\Logistics\Shipments\Http\Requests\AssignShipmentRequest;
use App\Modules\Logistics\Shipments\Http\Requests\ChangeShipmentStatusRequest;
use App\Modules\Logistics\Shipments\Http\Requests\StoreShipmentRequest;
use App\Modules\Logistics\Shipments\ReadModels\ShipmentReadModel;
use App\Modules\Logistics\Shipments\UseCases\AssignShipment;
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
            assignedToUserId: $request->filled('assigned_to') ? (int) $request->input('assigned_to') : null,
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

    /** ADR-822 — assignable people with their driver identifier (read model only). */
    public function assignees(Request $request): JsonResponse
    {
        return response()->json(ShipmentReadModel::assignees($request->attributes->get('company')));
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

    /** ADR-818 (chantier H) — plan / reschedule a shipment that is not on the road yet. */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'origin_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'destination_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $company = $request->attributes->get('company');
        \App\Modules\Logistics\Shipments\UseCases\RescheduleShipment::handle($company, $request->user(), $id, $data);

        return response()->json(['shipment' => ShipmentReadModel::find(company: $company, id: $id)]);
    }

    public function assign(AssignShipmentRequest $request, int $id): JsonResponse
    {
        try {
            $shipment = AssignShipment::handle(
                company: $request->attributes->get('company'),
                shipmentId: $id,
                userId: $request->input('user_id'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Shipment assigned.',
            // ADR-822 : même lecture que le détail (personne affectée + identifiant chauffeur lu dans le champ relié)
            'shipment' => ShipmentReadModel::find(company: $request->attributes->get('company'), id: $shipment->id),
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
            'shipment' => ShipmentReadModel::find(company: $request->attributes->get('company'), id: $shipment->id), // ADR-822
        ]);
    }
}
