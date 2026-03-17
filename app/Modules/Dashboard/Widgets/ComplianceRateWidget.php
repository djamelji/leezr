<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class ComplianceRateWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'compliance.rate';
    }

    public function module(): string
    {
        return 'core.dashboard';
    }

    public function layout(): array
    {
        return [
            'default_w' => 3,
            'default_h' => 2,
            'min_w' => 3,
            'max_w' => 6,
            'min_h' => 2,
            'max_h' => 4,
        ];
    }

    public function category(): string
    {
        return 'compliance';
    }

    public function component(): string
    {
        return 'ComplianceRate';
    }

    public function tags(): array
    {
        return ['compliance', 'kpi', 'rate'];
    }

    public function labelKey(): string
    {
        return 'compliance.widgets.rate';
    }

    public function descriptionKey(): string
    {
        return 'compliance.widgets.rateDesc';
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
        return [];
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
        return null;
    }
}
