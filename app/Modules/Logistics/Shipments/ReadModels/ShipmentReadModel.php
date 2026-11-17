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

        $page = $query->paginate($perPage);
        self::withDriverIdentifier($company, $page->getCollection());

        return $page;
    }

    /**
     * ADR-820 — the driver identifier is READ from the company's own field bound to the module need
     * (never copied, absent while the company has not bound it).
     */
    public static function withDriverIdentifier(Company $company, iterable $shipments): void
    {
        $needs = new \App\Core\Modules\Data\ModuleDataNeeds($company);
        if (! $needs->mapping('logistics_shipments', 'driver_identifier')) {
            return;
        }
        $cache = [];
        foreach ($shipments as $s) {
            $uid = $s->assigned_to_user_id;
            $s->setAttribute('driver_identifier', $uid ? ($cache[$uid] ??= $needs->valueFor('logistics_shipments', 'driver_identifier', (int) $uid)) : null);
        }
    }

    /**
     * Single shipment by ID, scoped to company.
     */
    public static function find(Company $company, int $id): Shipment
    {
        $shipment = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,first_name,last_name', 'assignedTo:id,first_name,last_name')
            ->findOrFail($id);
        self::withDriverIdentifier($company, [$shipment]);

        return $shipment;
    }
}
