<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Shipment;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class DeliveriesNextWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'deliveries.next';
    }

    public function module(): string
    {
        return 'logistics_shipments';
    }

    public function layout(): array
    {
        return [
            'default_w' => 3,
            'default_h' => 2,
            'min_w' => 2,
            'max_w' => 6,
            'min_h' => 2,
            'max_h' => 4,
        ];
    }

    public function category(): string
    {
        return 'operations';
    }

    public function component(): string
    {
        return 'DeliveriesNext';
    }

    public function tags(): array
    {
        return ['deliveries', 'kpi', 'operations'];
    }

    public function labelKey(): string
    {
        return 'shipments.widgets.nextDelivery';
    }

    public function descriptionKey(): string
    {
        return 'shipments.widgets.nextDeliveryDesc';
    }

    public function audience(): string
    {
        return 'company';
    }

    public function scope(): string
    {
        return 'company';
    }

    public function resolution(): string
    {
        return 'server';
    }

    public function permissions(): array
    {
        return ['shipments.view_own'];
    }

    public function capabilities(): array
    {
        return [];
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function resolve(array $context): array
    {
        $shipment = Shipment::where('assigned_to_user_id', auth()->id())
            ->whereIn('status', ['planned', 'in_transit'])
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at', 'asc')
            ->first();

        return [
            'key' => $this->key(),
            'scope' => 'company',
            'data' => [
                'reference' => $shipment?->reference,
                'destination' => $shipment?->destination_address,
                'scheduled_at' => $shipment?->scheduled_at?->toIso8601String(),
            ],
        ];
    }

    public function archetypes(): ?array
    {
        return ['field_worker'];
    }
}
