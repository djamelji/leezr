<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;


/** ADR-744 (P1) — widget workforce.headcount (server-resolved, données réelles, gated workforce.view). */
class WorkforceHeadcountWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'workforce.headcount'; }
    public function module(): string { return 'workforce'; }
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 2, 'max_w' => 6, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'workforce'; }
    public function component(): string { return 'WorkforceHeadcount'; }
    public function tags(): array { return ['workforce', 'workforce', 'kpi']; }
    public function labelKey(): string { return 'workforce.widgets.WorkforceHeadcount'; }
    public function descriptionKey(): string { return 'workforce.widgets.WorkforceHeadcountDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['workforce.view']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management','operations_center']; }

    public function resolve(array $context): array
    {
        $cid = (int) ($context['company_id'] ?? 0);
        try {
            return [
                'active' => \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'active')->count(),
                'on_leave' => \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'on_leave')->count(),
                'total' => \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $cid)->count(),
            ];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
