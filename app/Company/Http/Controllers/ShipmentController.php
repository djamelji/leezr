<?php

namespace App\Company\Http\Controllers;

use App\Company\Http\Requests\ChangeShipmentStatusRequest;
use App\Company\Http\Requests\StoreShipmentRequest;
use App\Core\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController
{
    /**
     * List shipments for the current company (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $query = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at');

        // Optional status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Optional search on reference
        if ($request->filled('search')) {
            $query->where('reference', 'like', '%' . $request->input('search') . '%');
        }

        $shipments = $query->paginate($request->input('per_page', 15));

        return response()->json($shipments);
    }

    /**
     * Create a new shipment (draft).
     */
    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        $shipment = Shipment::create([
            'company_id' => $company->id,
            'created_by_user_id' => $user->id,
            'reference' => Shipment::generateReference($company->id),
            'status' => Shipment::STATUS_DRAFT,
            'origin_address' => $request->input('origin_address'),
            'destination_address' => $request->input('destination_address'),
            'scheduled_at' => $request->input('scheduled_at'),
            'notes' => $request->input('notes'),
        ]);

        $shipment->load('createdBy:id,name');

        return response()->json([
            'message' => 'Shipment created.',
            'shipment' => $shipment,
        ], 201);
    }

    /**
     * Show a single shipment.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $shipment = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,name')
            ->findOrFail($id);

        return response()->json([
            'shipment' => $shipment,
        ]);
    }

    /**
     * Change a shipment's status (with transition validation).
     */
    public function changeStatus(ChangeShipmentStatusRequest $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $shipment = Shipment::where('company_id', $company->id)
            ->findOrFail($id);

        $newStatus = $request->input('status');

        if (!$shipment->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "Cannot transition from '{$shipment->status}' to '{$newStatus}'.",
            ], 422);
        }

        $shipment->update(['status' => $newStatus]);
        $shipment->load('createdBy:id,name');

        return response()->json([
            'message' => 'Shipment status updated.',
            'shipment' => $shipment,
        ]);
    }
}
