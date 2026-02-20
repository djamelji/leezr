<?php

namespace App\Modules\Logistics\Shipments\ReadModels;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MyDeliveryReadModel
{
    /**
     * Paginated list of shipments assigned to a specific user within a company.
     */
    public static function list(
        Company $company,
        int $userId,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = Shipment::where('company_id', $company->id)
            ->where('assigned_to_user_id', $userId)
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('scheduled_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Single shipment by ID, scoped to company + assignment.
     */
    public static function find(Company $company, int $userId, int $id): Shipment
    {
        return Shipment::where('company_id', $company->id)
            ->where('assigned_to_user_id', $userId)
            ->with('createdBy:id,first_name,last_name')
            ->findOrFail($id);
    }
}
