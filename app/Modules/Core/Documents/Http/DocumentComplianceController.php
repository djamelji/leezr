<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\ReadModels\DocumentActivityReadModel;
use App\Core\Documents\ReadModels\DocumentComplianceReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-387/396: Document compliance dashboard + activity endpoint (passive).
 *
 * Returns lifecycle-based compliance stats for the company:
 * summary KPIs, breakdown by role, breakdown by document type.
 * Also returns recent document activity from audit logs.
 */
class DocumentComplianceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            DocumentComplianceReadModel::forCompany($company),
        );
    }

    public function activity(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'activity' => DocumentActivityReadModel::forCompany($company->id),
        ]);
    }
}
