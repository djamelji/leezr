<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Shipment;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class ShipmentsTodayWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'shipments.today';
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
        return 'ShipmentsToday';
    }

    public function tags(): array
    {
        return ['shipments', 'kpi', 'operations'];
    }

    public function labelKey(): string
    {
        return 'shipments.widgets.today';
    }

    public function descriptionKey(): string
    {
        return 'shipments.widgets.todayDesc';
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
        return ['shipments.view'];
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
        $count = Shipment::where('company_id', $context['company_id'])
            ->whereDate('scheduled_at', now()->toDateString())
            ->count();

        return [
            'key' => $this->key(),
            'scope' => 'company',
            'data' => ['count' => $count],
        ];
    }

    public function archetypes(): ?array
    {
        return ['management', 'operations_center'];
    }
}
