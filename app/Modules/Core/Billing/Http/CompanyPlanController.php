<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Plans\PlanRegistry;
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

        return response()->json(['plan_key' => $validated['plan_key']]);
    }
}
