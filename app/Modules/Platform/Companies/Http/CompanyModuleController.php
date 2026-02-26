<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleCatalogReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyModuleController
{
    public function enable(Request $request, int $companyId, string $key): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $result = ModuleActivationEngine::enable($company, $key);

        if ($result['success']) {
            Log::info('platform.module.enabled', [
                'module_key' => $key,
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'activated' => $result['data']['activated'] ?? [],
            ]);

            app(AuditLogger::class)->logPlatform(
                AuditAction::MODULE_ENABLED, 'company_module', "{$company->id}:{$key}",
                ['metadata' => ['company_id' => $company->id, 'module_key' => $key]],
            );
        }

        return response()->json($result['data'], $result['status']);
    }

    public function disable(Request $request, int $companyId, string $key): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $result = ModuleActivationEngine::disable($company, $key);

        if ($result['success']) {
            Log::info('platform.module.disabled', [
                'module_key' => $key,
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'deactivated' => $result['data']['deactivated'] ?? [],
            ]);

            app(AuditLogger::class)->logPlatform(
                AuditAction::MODULE_DISABLED, 'company_module', "{$company->id}:{$key}",
                ['metadata' => ['company_id' => $company->id, 'module_key' => $key]],
            );
        }

        return response()->json($result['data'], $result['status']);
    }
}
