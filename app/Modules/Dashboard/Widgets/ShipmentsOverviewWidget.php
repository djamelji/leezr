<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Shipment;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-745 — panneau composite « Synthèse expéditions » (server-resolved, données réelles).
 * Agrège en un seul payload les compteurs clés + la répartition par statut (donut),
 * pour remplacer 4 KPI uniformes par un panneau analytique. Scopé company_id.
 */
class ShipmentsOverviewWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'shipments.overview'; }
    public function module(): string { return 'logistics_shipments'; }
    public function layout(): array { return ['default_w' => 8, 'default_h' => 4, 'min_w' => 4, 'max_w' => 12, 'min_h' => 3, 'max_h' => 4]; }
    public function category(): string { return 'operations'; }
    public function component(): string { return 'ShipmentsOverview'; }
    public function tags(): array { return ['shipments', 'operations', 'overview']; }
    public function labelKey(): string { return 'shipments.widgets.overview'; }
    public function descriptionKey(): string { return 'shipments.widgets.overviewDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['shipments.view']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management', 'operations_center']; }

    public function resolve(array $context): array
    {
        $cid = (int) ($context['company_id'] ?? 0);
        try {
            $base = fn () => Shipment::where('company_id', $cid);

            $byStatus = $base()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
            $status = [];
            foreach (Shipment::STATUSES as $s) {
                $status[$s] = (int) ($byStatus[$s] ?? 0);
            }

            return [
                'today' => $base()->whereDate('scheduled_at', now()->toDateString())->count(),
                'in_transit' => (int) ($status['in_transit'] ?? 0),
                'late' => $base()->where('status', 'in_transit')->where('scheduled_at', '<', now())->count(),
                'unassigned' => $base()->whereNull('assigned_to_user_id')->whereNotIn('status', ['delivered', 'canceled'])->count(),
                'drivers_active' => $base()->where('status', 'in_transit')->whereNotNull('assigned_to_user_id')->distinct('assigned_to_user_id')->count('assigned_to_user_id'),
                'total' => array_sum($status),
                'by_status' => $status,
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
