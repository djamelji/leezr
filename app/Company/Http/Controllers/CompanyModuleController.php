<?php

namespace App\Company\Http\Controllers;

use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\PlatformModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyModuleController
{
    /**
     * List all modules with activation status and capabilities for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'modules' => ModuleCatalogReadModel::forCompany($company),
        ]);
    }

    /**
     * Enable a module for the current company.
     */
    public function enable(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');

        if (!ModuleGate::isEnabledGlobally($key)) {
            return response()->json([
                'message' => 'Module is not available globally.',
            ], 422);
        }

        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $key],
            ['is_enabled_for_company' => true],
        );

        return response()->json([
            'message' => 'Module enabled.',
            'modules' => ModuleCatalogReadModel::forCompany($company),
        ]);
    }

    /**
     * Disable a module for the current company.
     */
    public function disable(Request $request, string $key): JsonResponse
    {
        $company = $request->attributes->get('company');

        $platformModule = PlatformModule::where('key', $key)->first();

        if (!$platformModule) {
            return response()->json([
                'message' => 'Module not found.',
            ], 404);
        }

        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $key],
            ['is_enabled_for_company' => false],
        );

        return response()->json([
            'message' => 'Module disabled.',
            'modules' => ModuleCatalogReadModel::forCompany($company),
        ]);
    }
}
