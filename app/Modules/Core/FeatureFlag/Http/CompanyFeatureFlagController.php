<?php

namespace App\Modules\Core\FeatureFlag\Http;

use App\Core\FeatureFlag\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CompanyFeatureFlagController extends Controller
{
    /** ADR-786 : flags of the ACTIVE company (X-Company-Id, resolved by SetCompanyContext), never a user attribute. */
    public function __invoke(Request $request, FeatureFlagService $service): JsonResponse
    {
        return response()->json($service->resolvedForCompany((int) $request->attributes->get('company')->id));
    }
}
