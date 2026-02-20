<?php

namespace App\Modules\Logistics\Shipments\ReadModels;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShipmentReadModel
{
    /**
     * Paginated list of shipments for a company with optional filters.
     */
    public static function list(
        Company $company,
        ?string $status = null,
        ?string $search = null,
        ?int $assignedToUserId = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,first_name,last_name', 'assignedTo:id,first_name,last_name')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where('reference', 'like', "%{$search}%");
        }

        if ($assignedToUserId !== null) {
            $query->where('assigned_to_user_id', $assignedToUserId);
        }

        return $query->paginate($perPage);
    }

    /**
     * Single shipment by ID, scoped to company.
     */
    public static function find(Company $company, int $id): Shipment
    {
        return Shipment::where('company_id', $company->id)
            ->with('createdBy:id,first_name,last_name', 'assignedTo:id,first_name,last_name')
            ->findOrFail($id);
    }
}
