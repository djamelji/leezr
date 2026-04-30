<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Core\Usage\UsageAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Usage monitoring endpoints — platform-wide and per-company.
 */
class UsageMonitoringController extends Controller
{
    public function overview(UsageAnalyticsService $service, Request $request): JsonResponse
    {
        $days = min((int) $request->query('days', 30), 90);

        return response()->json($service->platformOverview($days));
    }

    public function company(UsageAnalyticsService $service, Request $request, int $companyId): JsonResponse
    {
        $days = min((int) $request->query('days', 30), 90);

        return response()->json($service->companyDetail($companyId, $days));
    }
}
