<?php

namespace App\Company\Http\Controllers;

use App\Core\Events\ModuleDisabled;
use App\Core\Events\ModuleEnabled;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\DependencyResolver;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
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

        $entitlement = EntitlementResolver::check($company, $key);

        if (!$entitlement['entitled']) {
            $messages = [
                'plan_required' => 'This module requires a higher plan.',
                'incompatible_jobdomain' => 'This module is not available for your industry.',
                'not_available' => 'This module is not included in your plan.',
            ];

            return response()->json([
                'message' => $messages[$entitlement['reason']] ?? 'Module not available.',
                'reason' => $entitlement['reason'],
            ], 422);
        }

        $deps = DependencyResolver::canActivate($company, $key);

        if (!$deps['can_activate']) {
            return response()->json([
                'message' => 'Required modules must be activated first.',
                'missing' => $deps['missing'],
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

        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if ($manifest && $manifest->type === 'core') {
            return response()->json([
                'message' => 'Core modules cannot be disabled.',
            ], 422);
        }

        $deps = DependencyResolver::canDeactivate($company, $key);

        if (!$deps['can_deactivate']) {
            return response()->json([
                'message' => 'Other modules depend on this one.',
                'dependents' => $deps['dependents'],
            ], 422);
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
