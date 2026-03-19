<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\BillingMetricsCalculationService;
use Illuminate\Http\JsonResponse;

class PlatformBillingMetricsController
{
    public function __invoke(BillingMetricsCalculationService $service): JsonResponse
    {
        return response()->json($service->calculate());
    }
}
