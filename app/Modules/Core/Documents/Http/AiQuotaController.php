<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Ai\AiQuotaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-436: Company-level AI quota endpoint.
 *
 * Returns usage/limit/remaining for a given AI module key.
 */
class AiQuotaController
{
    public function show(Request $request, string $moduleKey): JsonResponse
    {
        $company = $request->attributes->get('company');

        $used = AiQuotaManager::usageThisMonth($company->id, $moduleKey);
        $limit = AiQuotaManager::quotaLimit($company, $moduleKey);
        $remaining = max(0, $limit - $used);

        return response()->json([
            'module_key' => $moduleKey,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'percentage' => $limit > 0 ? round(($used / $limit) * 100) : 0,
        ]);
    }
}
