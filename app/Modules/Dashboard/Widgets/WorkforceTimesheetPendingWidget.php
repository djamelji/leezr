<?php

namespace App\Modules\Dashboard\Widgets;

use App\Modules\Dashboard\Contracts\WidgetLayoutDefaults;
use App\Modules\Dashboard\Contracts\CollectiveWidget;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use Illuminate\Support\Carbon;


/** ADR-744 (P1) — widget workforce.timesheet_pending (server-resolved, données réelles, gated workforce.timesheet_approve). */
class WorkforceTimesheetPendingWidget implements WidgetManifest, CollectiveWidget
{
    use WidgetLayoutDefaults;

    public function key(): string { return 'workforce.timesheet_pending'; }
    public function module(): string { return 'workforce'; }
    public function layout(): array { return ['default_w' => 3, 'default_h' => 2, 'min_w' => 3, 'max_w' => 6, 'min_h' => 2, 'max_h' => 2]; } // ADR-856 — KPI family bounds
    public function category(): string { return 'workforce'; }
    public function component(): string { return 'WorkforceTimesheetPending'; }
    public function tags(): array { return ['workforce', 'workforce', 'kpi']; }
    public function labelKey(): string { return 'workforce.widgets.WorkforceTimesheetPending'; }
    public function descriptionKey(): string { return 'workforce.widgets.WorkforceTimesheetPendingDesc'; }
    public function audience(): string { return 'company'; }
    public function scope(): string { return 'company'; }
    public function resolution(): string { return 'server'; }
    public function permissions(): array { return ['workforce.timesheet_approve']; }
    public function capabilities(): array { return []; }
    public function defaultConfig(): array { return []; }
    public function archetypes(): ?array { return ['management','operations_center']; }

    public function resolve(array $context): array
    {
        $cid = (int) ($context['company_id'] ?? 0);
        try {
            return ['pending' => \App\Core\Workforce\TimesheetPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'submitted')->count()];
        } catch (\Throwable $e) {
            return ['error' => true];
        }
    }

    /** ADR-816 — company-wide aggregate : requires the « see all » authority of this domain. */
    public function collectiveDomain(): string
    {
        return 'time';
    }
}
