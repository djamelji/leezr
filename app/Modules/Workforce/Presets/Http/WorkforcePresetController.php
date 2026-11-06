<?php

namespace App\Modules\Workforce\Presets\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Presets\WorkforcePresetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import des presets métier JobDomain dans la Company (Refonte Smart Planning — M5, ADR-728).
 * Lecture planning_view (preview), écriture planning_manage (import). Idempotent, scopé Company.
 */
class WorkforcePresetController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $company = Company::findOrFail($request->attributes->get('company_id'));

        return response()->json(app(WorkforcePresetImportService::class)->preview($company));
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'selected' => 'nullable|array',
            'selected.*' => 'string|max:120',
        ]);
        $companyId = $request->attributes->get('company_id');
        $company = Company::findOrFail($companyId);

        $result = app(WorkforcePresetImportService::class)->apply($company, $validated['selected'] ?? null, $request->user()?->id);

        app(AuditLogger::class)->logCompany($companyId, 'workforce_presets.imported', 'jobdomain', $company->jobdomain_key, ['metadata' => [
            'category' => 'workforce.planning', 'created' => count($result['created'] ?? []), 'conflicts' => count($result['conflicts'] ?? []),
        ]]);

        return response()->json($result, 201);
    }
}
