<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;


/** ADR-744 (P1) — widget workforce.leave_pending (server-resolved, données réelles, gated workforce.leave_request). */
class WorkforceLeavePendingWidget implements WidgetManifest
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'workforce.leave_pending'; }
    public function module(): string { return 'workforce_leave'; }
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 2, 'max_w' => 6, 'min_h' => 2, 'max_h' => 4]; }
    public function category(): string { return 'workforce'; }
    public function component(): string { return 'WorkforceLeavePending'; }
    public function tags(): array { return ['workforce', 'workforce', 'kpi']; }
    public function labelKey(): string { return 'workforce.widgets.WorkforceLeavePending'; }
    public function descriptionKey(): string { return 'workforce.widgets.WorkforceLeavePendingDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['workforce.leave_request']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management','operations_center']; }

    public function resolve(array $context): array
    {
        $cid = (int) ($context['company_id'] ?? 0);
        try {
            return ['pending' => \App\Core\Workforce\LeaveRequest::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'pending')->count()];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }
}
