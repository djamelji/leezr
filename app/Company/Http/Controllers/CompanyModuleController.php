<?php

namespace App\Company\Http\Controllers;

use App\Core\Events\ModuleDisabled;
use App\Core\Events\ModuleEnabled;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\PlatformModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        Log::info('module.enabled', [
            'module_key' => $key,
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
        ]);

        ModuleEnabled::dispatch($company, $key);

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

        Log::info('module.disabled', [
            'module_key' => $key,
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
        ]);

        ModuleDisabled::dispatch($company, $key);

        return response()->json([
            'message' => 'Module disabled.',
            'modules' => ModuleCatalogReadModel::forCompany($company),
        ]);
    }
}
