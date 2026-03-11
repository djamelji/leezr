<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class ComplianceTypesWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'compliance.types';
    }

    public function module(): string
    {
        return 'core.dashboard';
    }

    public function layout(): array
    {
        return [
            'default_w' => 6,
            'default_h' => 4,
            'min_w' => 4,
            'max_w' => 12,
            'min_h' => 3,
            'max_h' => 8,
        ];
    }

    public function category(): string
    {
        return 'compliance';
    }

    public function component(): string
    {
        return 'ComplianceTypes';
    }

    public function tags(): array
    {
        return ['compliance', 'types', 'list'];
    }

    public function labelKey(): string
    {
        return 'compliance.widgets.types';
    }

    public function descriptionKey(): string
    {
        return 'compliance.widgets.typesDesc';
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
}
