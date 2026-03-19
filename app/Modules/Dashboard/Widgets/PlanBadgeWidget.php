<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

/**
 * ADR-372: Pipeline-driven plan badge widget.
 * Shows current plan info. Requires billing.manage permission.
 * Client-resolved — frontend reads plan from auth store.
 */
class PlanBadgeWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'billing.plan_badge';
    }

    public function module(): string
    {
        return 'core.billing';
    }

    public function layout(): array
    {
        return [
            'default_w' => 12,
            'default_h' => 2,
            'min_w' => 6,
            'max_w' => 12,
            'min_h' => 2,
            'max_h' => 3,
        ];
    }

    public function category(): string
    {
        return 'billing';
    }

    public function component(): string
    {
        return 'PlanBadge';
    }

    public function tags(): array
    {
        return ['billing', 'plan', 'badge'];
    }

    public function labelKey(): string
    {
        return 'dashboard.widgets.planBadge';
    }

    public function descriptionKey(): string
    {
        return 'dashboard.widgets.planBadgeDesc';
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
        return 'client';
    }

    public function permissions(): array
    {
        return ['billing.manage'];
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
        return [];
    }

    public function archetypes(): ?array
    {
        return ['management'];
    }
}
