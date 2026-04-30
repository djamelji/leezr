<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Modules\Platform\Dashboard\OnboardingFunnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OnboardingFunnelController extends Controller
{
    public function __invoke(OnboardingFunnelService $service, Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);

        return response()->json($service->analytics(min($days, 90)));
    }
}
