<?php

namespace App\Modules\Logistics\Shipments\Home;

use App\Core\Home\HomeContext;
use App\Core\Home\HomePriority;
use App\Core\Home\HomePriorityProvider;
use App\Core\Models\Shipment;

/**
 * ADR-776 — Shipments contribution to the company home (operational anomalies of this business
 * module). Same definitions as the existing dashboard indicators (unassigned, late).
 */
class ShipmentsHomePriorities implements HomePriorityProvider
{
    public function priorities(HomeContext $home): array
    {
        $items = [];
        $base = fn () => Shipment::where('company_id', $home->company->id);

        if ($home->can('shipments.view')) {
            $late = $base()->where('status', 'in_transit')->where('scheduled_at', '<', now())->count();
            $items[] = HomePriority::make('shipments_late', $late, 'error', 'tabler-truck-return', ['name' => 'company-shipments', 'query' => ['status' => 'in_transit']], 'activity');
        }
        if ($home->can('shipments.view') && $home->can('shipments.assign')) {
            $unassigned = $base()->whereNull('assigned_to_user_id')->whereNotIn('status', ['delivered', 'canceled'])->count();
            $items[] = HomePriority::make('shipments_unassigned', $unassigned, 'warning', 'tabler-user-question', ['name' => 'company-shipments'], 'activity');
        }

        return $items;
    }
}
