<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Plans\PlanRegistry;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * ADR-100: Company plan management (change plan via BillingProvider).
 */
class CompanyPlanController extends Controller
{
    public function update(Request $request, BillingProvider $billing): JsonResponse
    {
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
        ]);

        $company = $request->attributes->get('company');

        $billing->changePlan($company, $validated['plan_key']);

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('plan.changed', $company->id, ['plan_key' => $validated['plan_key']])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::PLAN_CHANGED, 'company', (string) $company->id);

        return response()->json(['plan_key' => $validated['plan_key']]);
    }
}
