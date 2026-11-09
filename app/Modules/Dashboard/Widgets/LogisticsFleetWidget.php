<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Shipment;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-745 — panneau composite « Flotte & livraisons » (server-resolved, données réelles).
 * Regroupe chauffeurs actifs, livraisons du jour (réalisées / prévues) et la prochaine
 * livraison planifiée (company-wide). Scopé company_id.
 */
class LogisticsFleetWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'logistics.fleet'; }
    public function module(): string { return 'logistics_shipments'; }
    public function layout(): array { return ['default_w' => 4, 'default_h' => 4, 'min_w' => 3, 'max_w' => 12, 'min_h' => 3, 'max_h' => 4]; }
    public function category(): string { return 'operations'; }
    public function component(): string { return 'LogisticsFleet'; }
    public function tags(): array { return ['deliveries', 'operations', 'fleet']; }
    public function labelKey(): string { return 'shipments.widgets.fleet'; }
    public function descriptionKey(): string { return 'shipments.widgets.fleetDesc'; }
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

            $next = $base()->whereIn('status', ['planned', 'in_transit'])
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at', 'asc')
                ->first();

            return [
                'drivers_active' => $base()->where('status', 'in_transit')->whereNotNull('assigned_to_user_id')->distinct('assigned_to_user_id')->count('assigned_to_user_id'),
                'completed_today' => $base()->where('status', 'delivered')->whereDate('updated_at', today())->count(),
                'scheduled_today' => $base()->whereDate('scheduled_at', today())->count(),
                'next' => $next ? [
                    'reference' => $next->reference,
                    'destination' => $next->destination_address,
                    'scheduled_at' => $next->scheduled_at?->toIso8601String(),
                ] : null,
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
