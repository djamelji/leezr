<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Modules\Workforce\ReadModels\TimeCockpitReadModel;
use Illuminate\Support\Carbon;

/**
 * ADR-744 (P1) — Pointage en direct (donut présents/pause/non pointés).
 * Server-resolved sur le read-model réel ; gated par workforce.timesheet_view
 * (catalogue + batch API). Drill-down vers le cockpit temps.
 */
class WorkforceClockLiveWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string
    {
        return 'workforce.clock_live';
    }

    public function module(): string
    {
        return 'workforce';
    }

    public function layout(): array
    {
        return [
            'default_w' => 3,
            'default_h' => 2,
            'min_w' => 2,
            'max_w' => 4,
            'min_h' => 2,
            'max_h' => 3,
        ];
    }

    public function category(): string
    {
        return 'workforce';
    }

    public function component(): string
    {
        return 'WorkforceClockLive';
    }

    public function tags(): array
    {
        return ['workforce', 'time', 'pointage', 'kpi'];
    }

    public function labelKey(): string
    {
        return 'workforce.widgets.clockLive';
    }

    public function descriptionKey(): string
    {
        return 'workforce.widgets.clockLiveDesc';
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
        return ['workforce.timesheet_view'];
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
        $summary = TimeCockpitReadModel::summary((int) ($context['company_id'] ?? 0), Carbon::now());

        return [
            'working' => (int) ($summary['working'] ?? 0),
            'on_break' => (int) ($summary['on_break'] ?? 0),
            'not_clocked' => (int) ($summary['not_clocked'] ?? 0),
            'completed' => (int) ($summary['completed'] ?? 0),
            'anomalies' => (int) ($summary['anomalies'] ?? 0),
        ];
    }

    public function archetypes(): ?array
    {
        return ['management', 'operations_center'];
    }
}
