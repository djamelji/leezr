<?php

namespace App\Modules\Workforce\Home;

use App\Core\Home\HomeContext;
use App\Core\Home\HomePriorityProvider;
use App\Modules\Workforce\ReadModels\HomePrioritiesReadModel;

/**
 * ADR-776 — Workforce contribution to the company home. Single source of truth : the existing
 * authorised, manager-scoped, salary-free read model (ADR-682) — same gate as its own endpoint
 * (`workforce.view`), nothing recomputed here.
 */
class WorkforceHomePriorities implements HomePriorityProvider
{
    private const SPACE = [
        'leave_to_approve' => 'time', 'timesheets_to_approve' => 'time', 'time_anomalies' => 'time',
        'signatures_pending' => 'documents', 'payroll_to_control' => 'payroll',
    ];

    public function priorities(HomeContext $home): array
    {
        if (! $home->can('workforce.view')) {
            return [];
        }

        return array_map(
            fn (array $p) => $p + ['space' => self::SPACE[$p['key']] ?? 'team'],
            HomePrioritiesReadModel::forRequest($home->request)['priorities'] ?? [],
        );
    }
}
