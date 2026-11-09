<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;


/** ADR-744 (P1) — widget planning.shifts (server-resolved, données réelles, gated workforce.planning_view). */
class PlanningShiftsWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'planning.shifts'; }
    public function module(): string { return 'workforce'; }
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 2, 'max_w' => 6, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'planning'; }
    public function component(): string { return 'PlanningShifts'; }
    public function tags(): array { return ['planning', 'workforce', 'kpi']; }
    public function labelKey(): string { return 'workforce.widgets.PlanningShifts'; }
    public function descriptionKey(): string { return 'workforce.widgets.PlanningShiftsDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['workforce.planning_view']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management','operations_center']; }

    public function resolve(array $context): array
    {
        $cid = (int) ($context['company_id'] ?? 0);
        try {
            $from = Carbon::now()->startOfWeek()->toDateString(); $to = Carbon::now()->endOfWeek()->toDateString();
            $base = \App\Core\Workforce\Shift::withoutGlobalScopes()->where('company_id', $cid)->whereBetween('date', [$from, $to]);
            return [
                'total' => (clone $base)->whereIn('status', ['draft', 'published', 'completed'])->count(),
                'unassigned' => (clone $base)->whereNull('employee_id')->whereIn('status', ['draft', 'published'])->count(),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
