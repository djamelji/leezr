<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Modules\Platform\Dashboard\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * GET /platform/system/health
 *
 * Aggregated system health dashboard — delegates to SystemHealthService.
 */
class SystemHealthController extends Controller
{
    public function index(SystemHealthService $service): JsonResponse
    {
        return response()->json($service->check());
    }
}
