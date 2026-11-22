<?php

namespace App\Modules\Logistics\Shipments;

use App\Core\Fields\FunctionActivityEvidence;
use App\Core\Models\Shipment;

/**
 * ADR-861 — who actually drives : people with a delivery in progress, or assigned a delivery in the last 90 days
 * (the manager who drives now and then counts ; an old delivery from last year does not).
 */
final class ShipmentsDrivingEvidence implements FunctionActivityEvidence
{
    public const WINDOW_DAYS = 90;

    public static function holders(int $companyId): array
    {
        return Shipment::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('assigned_to_user_id')
            ->where(fn ($q) => $q->whereIn('status', [Shipment::STATUS_PLANNED, Shipment::STATUS_IN_TRANSIT])
                ->orWhere(fn ($r) => $r->where('status', '!=', Shipment::STATUS_CANCELED)->where('updated_at', '>=', now()->subDays(self::WINDOW_DAYS))))
            ->distinct()->pluck('assigned_to_user_id')->map(fn ($v) => (int) $v)->all();
    }
}
