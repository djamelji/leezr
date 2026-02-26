<?php

namespace App\Modules\Platform\Audit\Http;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Audit\PlatformAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-130: Platform-level audit log viewer.
 *
 * Requires view_audit_logs platform permission.
 */
class PlatformAuditLogController
{
    /**
     * GET /platform/api/audit/platform
     * Paginated platform audit logs with filters.
     */
    public function platformLogs(Request $request): JsonResponse
    {
        $query = PlatformAuditLog::query()->orderByDesc('created_at');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($actorId = $request->query('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($targetType = $request->query('target_type')) {
            $query->where('target_type', $targetType);
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * GET /platform/api/audit/companies
     * Paginated company audit logs across all companies.
     */
    public function companyLogs(Request $request): JsonResponse
    {
        $query = CompanyAuditLog::query()->orderByDesc('created_at');

        if ($companyId = $request->query('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($actorId = $request->query('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($targetType = $request->query('target_type')) {
            $query->where('target_type', $targetType);
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * GET /platform/api/audit/actions
     * List all known audit action types for filter dropdown.
     */
    public function actions(): JsonResponse
    {
        $reflection = new \ReflectionClass(\App\Core\Audit\AuditAction::class);

        return response()->json([
            'actions' => array_values($reflection->getConstants()),
        ]);
    }
}
