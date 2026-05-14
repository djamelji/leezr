<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Audit\CompanyAuditLog;
use Illuminate\Support\Collection;

/**
 * Read model for DSN audit trail queries.
 * All methods are static, read-only — no mutations.
 *
 * Queries CompanyAuditLog where target_type='dsn_declaration'.
 *
 * Sprint 6.7 — ADR-525
 */
class DsnAuditReadModel
{
    private const TARGET_TYPE = 'dsn_declaration';

    /**
     * Full audit history for a specific DSN declaration.
     * Returns all audit entries ordered chronologically.
     */
    public static function historyForDeclaration(int $declarationId): Collection
    {
        return CompanyAuditLog::withoutGlobalScopes()
            ->where('target_type', self::TARGET_TYPE)
            ->where('target_id', (string) $declarationId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (CompanyAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_id' => $log->actor_id,
                'severity' => $log->severity,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Payload hash timeline for a declaration.
     * Shows how the hash evolved across export/regeneration events.
     */
    public static function payloadHashTimeline(int $declarationId): Collection
    {
        return CompanyAuditLog::withoutGlobalScopes()
            ->where('target_type', self::TARGET_TYPE)
            ->where('target_id', (string) $declarationId)
            ->whereIn('action', [
                'dsn_declaration.generated',
                'dsn_declaration.submitted',
                'dsn_declaration.accepted',
                'dsn_declaration.rejected',
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn (CompanyAuditLog $log) => [
                'action' => $log->action,
                'payload_hash' => $log->metadata['payload_hash'] ?? null,
                'status' => $log->metadata['status'] ?? null,
                'period_month' => $log->metadata['period_month'] ?? null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);
    }

    /**
     * All DSN audit entries for a company, paginated.
     */
    public static function forCompany(int $companyId, int $perPage = 25): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return CompanyAuditLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('target_type', self::TARGET_TYPE)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Summary of DSN actions for a company (counts by action type).
     */
    public static function actionSummary(int $companyId): array
    {
        $logs = CompanyAuditLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('target_type', self::TARGET_TYPE)
            ->get();

        $byAction = [];
        foreach ($logs as $log) {
            $byAction[$log->action] = ($byAction[$log->action] ?? 0) + 1;
        }

        return [
            'total' => $logs->count(),
            'by_action' => $byAction,
            'latest_at' => $logs->max('created_at')?->toIso8601String(),
        ];
    }
}
