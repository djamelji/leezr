<?php

namespace App\Modules\Platform\Activity\Http;

use App\Core\Audit\AuditAction;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Platform\Activity\ActivityAggregator;
use App\Modules\Platform\Activity\ActivityCategoryMap;
use App\Modules\Platform\Activity\ActivityDescriber;
use App\Modules\Platform\Activity\ActivityFeedQuery;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ADR-440: Platform-wide Activity Feed controller.
 *
 * Merges platform_audit_logs + company_audit_logs into a single
 * chronological feed with human-readable descriptions, actor/company
 * resolution, category-based filtering, and same-minute aggregation.
 *
 * Query logic delegated to ActivityFeedQuery (no DB:: in controller).
 * Description logic delegated to ActivityDescriber.
 * Aggregation logic delegated to ActivityAggregator.
 */
class PlatformActivityController
{
    /**
     * GET /platform/api/activity
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);
        $page = max((int) $request->query('page', 1), 1);

        $query = new ActivityFeedQuery($request, $perPage, $page);
        [$rows, $total] = $query->execute();

        $items = $this->buildItems($rows);
        $aggregated = ActivityAggregator::aggregate($items);

        return response()->json([
            'data' => $aggregated->values(),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
        ]);
    }

    /**
     * GET /platform/api/activity/types
     */
    public function types(): JsonResponse
    {
        $reflection = new \ReflectionClass(AuditAction::class);
        $constants = $reflection->getConstants();

        $grouped = [];

        foreach ($constants as $value) {
            $category = ActivityCategoryMap::categorize($value);
            $grouped[$category][] = [
                'value' => $value,
                'label' => ActivityDescriber::humanLabel($value),
            ];
        }

        return response()->json([
            'categories' => ActivityCategoryMap::categories(),
            'actions_by_category' => $grouped,
        ]);
    }

    // ── Private ──────────────────────────────────────────────

    private function buildItems(Collection $rows): Collection
    {
        $actorIds = $rows->pluck('actor_id')->filter()->unique();
        $companyIds = $rows->pluck('company_id')->filter()->unique();

        $adminNames = $this->resolveAdminNames($actorIds);
        $userNames = $this->resolveUserNames($actorIds);
        $companyNames = $this->resolveCompanyNames($companyIds);

        return $rows->map(function ($row) use ($adminNames, $userNames, $companyNames) {
            $actorName = $this->resolveActorName($row, $adminNames, $userNames);
            $companyName = $row->company_id
                ? $companyNames->get($row->company_id, 'Company #' . $row->company_id)
                : null;

            return [
                'id' => $row->id,
                'source' => $row->source,
                'action' => $row->action,
                'category' => ActivityCategoryMap::categorize($row->action),
                'actor_id' => $row->actor_id,
                'actor_name' => $actorName,
                'actor_type' => $row->actor_type,
                'target_type' => $row->target_type,
                'target_id' => $row->target_id,
                'company_id' => $row->company_id,
                'company_name' => $companyName,
                'description' => ActivityDescriber::describe(
                    $row->action,
                    $actorName,
                    $companyName,
                    $row->target_type,
                    $row->target_id,
                ),
                'severity' => $row->severity,
                'has_diff' => (bool) $row->has_diff,
                'created_at' => $row->created_at,
            ];
        });
    }

    private function resolveActorName(object $row, Collection $adminNames, Collection $userNames): string
    {
        return match ($row->actor_type) {
            'admin' => $adminNames->get($row->actor_id, 'Admin #' . $row->actor_id),
            'user' => $userNames->get($row->actor_id, 'User #' . $row->actor_id),
            'system' => 'System',
            'webhook' => 'Webhook',
            default => $row->actor_type,
        };
    }

    private function resolveAdminNames(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return PlatformUser::whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);
    }

    private function resolveUserNames(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);
    }

    private function resolveCompanyNames(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Company::whereIn('id', $ids)->pluck('name', 'id');
    }
}
