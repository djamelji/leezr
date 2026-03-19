<?php

namespace App\Modules\Dashboard\Widgets;

use App\Core\Models\Shipment;
use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class DeliveriesCompletedTodayWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'deliveries.completed_today';
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
        return 'DeliveriesCompletedToday';
    }

    public function tags(): array
    {
        return ['deliveries', 'kpi', 'operations'];
    }

    public function labelKey(): string
    {
        return 'shipments.widgets.completedToday';
    }

    public function descriptionKey(): string
    {
        return 'shipments.widgets.completedTodayDesc';
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
        $completed = Shipment::where('company_id', $context['company_id'])
            ->where('assigned_to_user_id', auth()->id())
            ->where('status', 'delivered')
            ->whereDate('updated_at', today())
            ->count();

        $total = Shipment::where('company_id', $context['company_id'])
            ->where('assigned_to_user_id', auth()->id())
            ->whereDate('scheduled_at', today())
            ->count();

        return [
            'key' => $this->key(),
            'scope' => 'company',
            'data' => [
                'completed' => $completed,
                'total' => $total,
            ],
        ];
    }

    public function archetypes(): ?array
    {
        return ['field_worker'];
    }
}
