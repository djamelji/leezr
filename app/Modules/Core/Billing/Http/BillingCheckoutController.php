<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\ChangePlanService;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingCheckoutController
{
    public function __invoke(Request $request, ChangePlanService $service): JsonResponse
    {
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in(PlanRegistry::keys())],
            'billing_interval' => ['sometimes', 'string', Rule::in(['monthly', 'yearly'])],
        ]);

        $company = $request->attributes->get('company');
        $result = $service->requestUpgrade(
            $company,
            $validated['plan_key'],
            $validated['billing_interval'] ?? PlatformBillingPolicy::instance()->default_billing_interval,
        );

        return response()->json($result->toArray());
    }
}
