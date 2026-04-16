<?php

namespace App\Modules\Platform\Alerts\Http;

use App\Core\Alerts\PlatformAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-438: Platform Alert Center — centralized alerting API.
 */
class PlatformAlertController
{
    /**
     * GET /platform/api/alerts
     * Paginated alerts with filters + KPI summary.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlatformAlert::query()
            ->with('company:id,name')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->when($request->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);

        $kpis = [
            'active_critical' => PlatformAlert::active()->critical()->count(),
            'active_total' => PlatformAlert::active()->count(),
            'resolved_24h' => PlatformAlert::where('status', 'resolved')
                ->where('resolved_at', '>=', now()->subDay())->count(),
        ];

        return response()->json([
            'alerts' => $query->paginate($perPage),
            'kpis' => $kpis,
        ]);
    }

    /**
     * GET /platform/api/alerts/count
     * Lightweight endpoint for nav badge.
     */
    public function count(): JsonResponse
    {
        return response()->json([
            'count' => PlatformAlert::active()->count(),
            'critical' => PlatformAlert::active()->critical()->count(),
        ]);
    }

    /**
     * PUT /platform/api/alerts/{alert}/acknowledge
     */
    public function acknowledge(PlatformAlert $alert, Request $request): JsonResponse
    {
        if ($alert->status !== 'active') {
            return response()->json(['message' => 'Alert is not in active status.'], 422);
        }

        $alert->acknowledge($request->user()->id);

        return response()->json(['alert' => $alert->fresh()]);
    }

    /**
     * PUT /platform/api/alerts/{alert}/resolve
     */
    public function resolve(PlatformAlert $alert): JsonResponse
    {
        if (! in_array($alert->status, ['active', 'acknowledged'])) {
            return response()->json(['message' => 'Alert cannot be resolved from current status.'], 422);
        }

        $alert->resolve();

        return response()->json(['alert' => $alert->fresh()]);
    }

    /**
     * PUT /platform/api/alerts/{alert}/dismiss
     */
    public function dismiss(PlatformAlert $alert): JsonResponse
    {
        if (! in_array($alert->status, ['active', 'acknowledged'])) {
            return response()->json(['message' => 'Alert cannot be dismissed from current status.'], 422);
        }

        $alert->dismiss();

        return response()->json(['alert' => $alert->fresh()]);
    }
}
