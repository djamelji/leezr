<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Audit\CompanyAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /company/billing/timeline
 *
 * Returns the last 20 billing-related audit events for the current company.
 * Used by the company billing Activity tab (ADR-314).
 */
class CompanyBillingTimelineController
{
    /**
     * Billing-related action prefixes to include in the timeline.
     */
    private const BILLING_ACTION_PREFIXES = [
        'billing.',
        'subscription.',
        'webhook.payment_',
        'webhook.refund_',
        'webhook.dispute_',
        'plan.changed',
        'addon.',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $query = CompanyAuditLog::where('company_id', $company->id)
            ->where(function ($q) {
                foreach (self::BILLING_ACTION_PREFIXES as $prefix) {
                    $q->orWhere('action', 'like', $prefix . '%');
                }
                // Exact match for plan.changed (no wildcard needed but harmless)
                $q->orWhere('action', 'plan.changed');
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'action', 'target_type', 'target_id', 'severity', 'metadata', 'created_at']);

        return response()->json([
            'events' => $query->map(fn (CompanyAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'severity' => $log->severity,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
        ]);
    }
}
