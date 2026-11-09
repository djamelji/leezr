<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;


/** ADR-744 (P1) — widget workforce.anomalies (server-resolved, données réelles, gated workforce.timesheet_view). */
class WorkforceAnomaliesWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'workforce.anomalies'; }
    public function module(): string { return 'workforce'; }
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 2, 'max_w' => 6, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'workforce'; }
    public function component(): string { return 'WorkforceAnomalies'; }
    public function tags(): array { return ['workforce', 'workforce', 'kpi']; }
    public function labelKey(): string { return 'workforce.widgets.WorkforceAnomalies'; }
    public function descriptionKey(): string { return 'workforce.widgets.WorkforceAnomaliesDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['workforce.timesheet_view']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management','operations_center']; }

    public function resolve(array $context): array
    {
        $cid = (int) ($context['company_id'] ?? 0);
        try {
            $s = \App\Modules\Workforce\ReadModels\TimeCockpitReadModel::summary($cid, Carbon::now());
            return ['anomalies' => (int) ($s['anomalies'] ?? 0), 'incomplete' => (int) ($s['incomplete'] ?? 0), 'not_clocked' => (int) ($s['not_clocked'] ?? 0)];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
