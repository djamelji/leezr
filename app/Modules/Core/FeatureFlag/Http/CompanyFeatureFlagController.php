<?php

namespace App\Modules\Core\FeatureFlag\Http;

use App\Core\FeatureFlag\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CompanyFeatureFlagController extends Controller
{
    public function __invoke(FeatureFlagService $service): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        return response()->json($service->resolvedForCompany($companyId));
    }
}
