<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;

class CompliancePendingWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'compliance.pending';
    }

    public function module(): string
    {
        return 'core.documents';
    }

    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 3, 'max_w' => 6, 'min_h' => 2, 'max_h' => 2]; } // ADR-856 — KPI family bounds

    public function category(): string
    {
        return 'compliance';
    }

    public function component(): string
    {
        return 'CompliancePending';
    }

    public function tags(): array
    {
        return ['compliance', 'kpi', 'pending'];
    }

    public function labelKey(): string
    {
        return 'compliance.widgets.pending';
    }

    public function descriptionKey(): string
    {
        return 'compliance.widgets.pendingDesc';
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
        return ['documents.view'];
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

    public function archetypes(): ?array { return ['management', 'operations_center']; } // ADR-751b: company-wide compliance = manager concern, excluded from field_worker home
}
