<?php

namespace App\Modules\Platform\Security\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Security\AlertTypeRegistry;
use App\Core\Security\SecurityAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-129: Platform security alert management.
 *
 * Requires manage_security_alerts platform permission.
 */
class SecurityAlertController
{
    /**
     * GET /platform/api/security/alerts
     * Paginated security alerts with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SecurityAlert::query()->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        if ($alertType = $request->query('alert_type')) {
            $query->where('alert_type', $alertType);
        }

        if ($companyId = $request->query('company_id')) {
            $query->where('company_id', $companyId);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return response()->json($query->paginate($perPage));
    }

    /**
     * PUT /platform/api/security/alerts/{id}/acknowledge
     */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $alert = SecurityAlert::findOrFail($id);

        if ($alert->status !== 'open') {
            return response()->json(['message' => 'Alert is not in open status.'], 422);
        }

        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        app(AuditLogger::class)->logPlatform(
            AuditAction::SECURITY_ALERT_ACKNOWLEDGED,
            'security_alert',
            $alert->id,
        );

        return response()->json(['alert' => $alert]);
    }

    /**
     * PUT /platform/api/security/alerts/{id}/resolve
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $alert = SecurityAlert::findOrFail($id);

        if (!in_array($alert->status, ['open', 'acknowledged'])) {
            return response()->json(['message' => 'Alert cannot be resolved from current status.'], 422);
        }

        $alert->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        app(AuditLogger::class)->logPlatform(
            AuditAction::SECURITY_ALERT_RESOLVED,
            'security_alert',
            $alert->id,
        );

        return response()->json(['alert' => $alert]);
    }

    /**
     * PUT /platform/api/security/alerts/{id}/false-positive
     */
    public function falsePositive(Request $request, string $id): JsonResponse
    {
        $alert = SecurityAlert::findOrFail($id);

        $alert->update([
            'status' => 'false_positive',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['alert' => $alert]);
    }

    /**
     * GET /platform/api/security/alert-types
     * List all known alert types for filter dropdown.
     */
    public function alertTypes(): JsonResponse
    {
        return response()->json([
            'alert_types' => AlertTypeRegistry::all(),
        ]);
    }
}
