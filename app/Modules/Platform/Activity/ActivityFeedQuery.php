<?php

namespace App\Modules\Platform\Activity;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Audit\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-440: Unified activity feed query — merges platform + company audit logs.
 *
 * Builds a UNION ALL across both tables with identical SELECT shapes,
 * applies filters, and returns paginated results.
 */
class ActivityFeedQuery
{
    public function __construct(
        private readonly Request $request,
        private readonly int $perPage = 30,
        private readonly int $page = 1,
    ) {}

    /**
     * Execute the union query and return [rows, total].
     *
     * @return array{Collection, int}
     */
    public function execute(): array
    {
        $platformQuery = $this->buildPlatformQuery();
        $companyQuery = $this->buildCompanyQuery();

        $this->applyFilters($platformQuery, hasCompanyId: false);
        $this->applyFilters($companyQuery, hasCompanyId: true);
        $this->applyCategoryFilter($platformQuery);
        $this->applyCategoryFilter($companyQuery);

        $union = $platformQuery->unionAll($companyQuery);

        $total = DB::table(DB::raw("({$union->toSql()}) as unified"))
            ->mergeBindings($union->getQuery())
            ->count();

        $rows = DB::table(DB::raw("({$union->toSql()}) as unified"))
            ->mergeBindings($union->getQuery())
            ->orderByDesc('created_at')
            ->offset(($this->page - 1) * $this->perPage)
            ->limit($this->perPage)
            ->get();

        return [$rows, $total];
    }

    private function buildPlatformQuery()
    {
        return PlatformAuditLog::query()->select([
            'id',
            DB::raw("'platform' as source"),
            'action',
            'actor_id',
            'actor_type',
            'target_type',
            'target_id',
            'severity',
            DB::raw('NULL as company_id'),
            DB::raw('CASE WHEN diff_before IS NOT NULL OR diff_after IS NOT NULL THEN 1 ELSE 0 END as has_diff'),
            'metadata',
            'created_at',
        ]);
    }

    private function buildCompanyQuery()
    {
        return CompanyAuditLog::query()->select([
            'id',
            DB::raw("'company' as source"),
            'action',
            'actor_id',
            'actor_type',
            'target_type',
            'target_id',
            'severity',
            'company_id',
            DB::raw('CASE WHEN diff_before IS NOT NULL OR diff_after IS NOT NULL THEN 1 ELSE 0 END as has_diff'),
            'metadata',
            'created_at',
        ]);
    }

    private function applyFilters($query, bool $hasCompanyId): void
    {
        if ($actorId = $this->request->query('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($severity = $this->request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($from = $this->request->query('date_from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $this->request->query('date_to')) {
            $query->where('created_at', '<=', $to);
        }

        if ($hasCompanyId && ($companyId = $this->request->query('company_id'))) {
            $query->where('company_id', $companyId);
        }
    }

    private function applyCategoryFilter($query): void
    {
        $type = $this->request->query('type');
        if (!$type) {
            return;
        }

        $prefixes = ActivityCategoryMap::prefixesFor($type);
        if (!$prefixes) {
            return;
        }

        $query->where(function ($q) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $q->orWhere('action', 'like', $prefix . '%');
            }
        });
    }
}
