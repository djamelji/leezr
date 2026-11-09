<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;


/** ADR-744 (P1) — widget workforce.hours_trend (server-resolved, données réelles, gated workforce.timesheet_view). */
class WorkforceHoursTrendWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'workforce.hours_trend'; }
    public function module(): string { return 'workforce'; }
    public function layout(): array { return ['default_w' => 6, 'default_h' => 3, 'min_w' => 2, 'max_w' => 6, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'workforce'; }
    public function component(): string { return 'WorkforceHoursTrend'; }
    public function tags(): array { return ['workforce', 'workforce', 'kpi']; }
    public function labelKey(): string { return 'workforce.widgets.WorkforceHoursTrend'; }
    public function descriptionKey(): string { return 'workforce.widgets.WorkforceHoursTrendDesc'; }
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
            $labels = []; $hours = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = Carbon::now()->subDays($i);
                $min = (int) \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->where('company_id', $cid)->whereDate('date', $d->toDateString())->sum('total_worked_minutes');
                $labels[] = ucfirst($d->locale('fr')->isoFormat('dd'));
                $hours[] = round($min / 60, 1);
            }
            return ['labels' => $labels, 'hours' => $hours];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
