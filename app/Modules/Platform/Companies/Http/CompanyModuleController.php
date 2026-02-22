<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModuleService;
use App\Core\Modules\ModuleCatalogReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyModuleController
{
    public function enable(Request $request, int $companyId, string $key): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $result = CompanyModuleService::enable($company, $key);

        if ($result['success']) {
            Log::info('platform.module.enabled', [
                'module_key' => $key,
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
            ]);

            $result['data']['modules'] = ModuleCatalogReadModel::forCompany($company);
        }

        return response()->json($result['data'], $result['status']);
    }

    public function disable(Request $request, int $companyId, string $key): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $result = CompanyModuleService::disable($company, $key);

        if ($result['success']) {
            Log::info('platform.module.disabled', [
                'module_key' => $key,
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
            ]);

            $result['data']['modules'] = ModuleCatalogReadModel::forCompany($company);
        }

        return response()->json($result['data'], $result['status']);
    }
}
