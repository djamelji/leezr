<?php

namespace App\Core\Modules;

use App\Core\Events\ModuleDisabled;
use App\Core\Events\ModuleEnabled;
use App\Core\Models\Company;

class CompanyModuleService
{
    /**
     * Enable a module for a company.
     *
     * @return array{success: bool, status: int, data: array}
     */
    public static function enable(Company $company, string $key): array
    {
        if (!ModuleGate::isEnabledGlobally($key)) {
            return [
                'success' => false,
                'status' => 422,
                'data' => ['message' => 'Module is not available globally.'],
            ];
        }

        $entitlement = EntitlementResolver::check($company, $key);

        if (!$entitlement['entitled']) {
            $messages = [
                'plan_required' => 'This module requires a higher plan.',
                'incompatible_jobdomain' => 'This module is not available for your industry.',
                'not_available' => 'This module is not included in your plan.',
            ];

            return [
                'success' => false,
                'status' => 422,
                'data' => [
                    'message' => $messages[$entitlement['reason']] ?? 'Module not available.',
                    'reason' => $entitlement['reason'],
                ],
            ];
        }

        $deps = DependencyResolver::canActivate($company, $key);

        if (!$deps['can_activate']) {
            return [
                'success' => false,
                'status' => 422,
                'data' => [
                    'message' => 'Required modules must be activated first.',
                    'missing' => $deps['missing'],
                ],
            ];
        }

        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $key],
            ['is_enabled_for_company' => true],
        );

        ModuleEnabled::dispatch($company, $key);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'message' => 'Module enabled.',
                'modules' => ModuleCatalogReadModel::forCompany($company),
            ],
        ];
    }

    /**
     * Disable a module for a company.
     *
     * @return array{success: bool, status: int, data: array}
     */
    public static function disable(Company $company, string $key): array
    {
        $platformModule = PlatformModule::where('key', $key)->first();

        if (!$platformModule) {
            return [
                'success' => false,
                'status' => 404,
                'data' => ['message' => 'Module not found.'],
            ];
        }

        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if ($manifest && $manifest->type === 'core') {
            return [
                'success' => false,
                'status' => 422,
                'data' => ['message' => 'Core modules cannot be disabled.'],
            ];
        }

        $deps = DependencyResolver::canDeactivate($company, $key);

        if (!$deps['can_deactivate']) {
            return [
                'success' => false,
                'status' => 422,
                'data' => [
                    'message' => 'Other modules depend on this one.',
                    'dependents' => $deps['dependents'],
                ],
            ];
        }

        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $key],
            ['is_enabled_for_company' => false],
        );

        ModuleDisabled::dispatch($company, $key);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'message' => 'Module disabled.',
                'modules' => ModuleCatalogReadModel::forCompany($company),
            ],
        ];
    }
}
