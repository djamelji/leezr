<?php

namespace App\Modules\Core\Audit\Http;

use App\Core\Audit\CompanyAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-130: Company-scoped audit log viewer.
 *
 * Requires audit.view company permission.
 */
class CompanyAuditLogController
{
    /**
     * GET /company/api/audit
     * Paginated audit logs for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $query = CompanyAuditLog::where('company_id', $company->id)
            ->orderByDesc('created_at');

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
}
