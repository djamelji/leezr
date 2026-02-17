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
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where('reference', 'like', "%{$search}%");
        }

        return $query->paginate($perPage);
    }

    /**
     * Single shipment by ID, scoped to company.
     */
    public static function find(Company $company, int $id): Shipment
    {
        return Shipment::where('company_id', $company->id)
            ->with('createdBy:id,name')
            ->findOrFail($id);
    }
}
