<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\User;

/**
 * ADR-396: Recent document activity for a company.
 *
 * Queries company audit logs for document-related actions,
 * returns last 15 entries with actor info.
 */
class DocumentActivityReadModel
{
    public static function forCompany(int $companyId, int $limit = 15): array
    {
        $logs = CompanyAuditLog::where('company_id', $companyId)
            ->where('action', 'like', 'document.%')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $actorIds = $logs->pluck('actor_id')->filter()->unique()->values();
        $actors = User::whereIn('id', $actorIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $logs->map(function (CompanyAuditLog $log) use ($actors) {
            $actor = $actors->get($log->actor_id);

            return [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $actor ? [
                    'id' => $actor->id,
                    'name' => "{$actor->first_name} {$actor->last_name}",
                ] : null,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toISOString(),
            ];
        })->values()->toArray();
    }
}
