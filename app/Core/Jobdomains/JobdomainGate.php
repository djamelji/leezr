<?php

namespace App\Core\Jobdomains;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Central service for jobdomain resolution.
 * All jobdomain-dependent logic passes through here — no if(jobdomain===...) elsewhere.
 */
class JobdomainGate
{
    /**
     * Resolve the jobdomain for a company. Returns null if none assigned.
     */
    public static function resolveForCompany(Company $company): ?Jobdomain
    {
        return $company->jobdomain;
    }

    /**
     * Get the landing route for a company's jobdomain.
     */
    public static function landingRouteFor(Company $company): string
    {
        $jobdomain = static::resolveForCompany($company);

        if (!$jobdomain) {
            return '/';
        }

        $definition = JobdomainRegistry::get($jobdomain->key);

        return $definition['landing_route'] ?? '/';
    }

    /**
     * Get the nav profile key for a company's jobdomain.
     */
    public static function navProfileFor(Company $company): ?string
    {
        $jobdomain = static::resolveForCompany($company);

        if (!$jobdomain) {
            return null;
        }

        $definition = JobdomainRegistry::get($jobdomain->key);

        return $definition['nav_profile'] ?? null;
    }

    /**
     * Get the default module keys for a jobdomain.
     * Reads from DB (editable via platform admin), falls back to Registry.
     */
    public static function defaultModulesFor(string $jobdomainKey): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();

        if ($jobdomain && !empty($jobdomain->default_modules)) {
            return $jobdomain->default_modules;
        }

        $definition = JobdomainRegistry::get($jobdomainKey);

        return $definition['default_modules'] ?? [];
    }

    /**
     * Get the default field presets for a jobdomain.
     * Returns structured array: [{code, required, order}, ...]
     * Reads from DB (editable via platform admin), falls back to Registry.
     */
    public static function defaultFieldsFor(string $jobdomainKey): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();

        if ($jobdomain && !empty($jobdomain->default_fields)) {
            return $jobdomain->default_fields;
        }

        $definition = JobdomainRegistry::get($jobdomainKey);

        return $definition['default_fields'] ?? [];
    }

    /**
     * Assign a jobdomain to a company and activate default modules + field presets.
     * Uses a transaction to ensure atomicity.
     */
    public static function assignToCompany(Company $company, string $jobdomainKey): Jobdomain
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)
            ->where('is_active', true)
            ->firstOrFail();

        return DB::transaction(function () use ($company, $jobdomain, $jobdomainKey) {
            // Assign (upsert via sync with single value)
            $company->jobdomains()->sync([$jobdomain->id]);

            // Activate default modules
            $defaultModules = static::defaultModulesFor($jobdomainKey);

            foreach ($defaultModules as $moduleKey) {
                if (ModuleGate::isEnabledGlobally($moduleKey)) {
                    CompanyModule::updateOrCreate(
                        ['company_id' => $company->id, 'module_key' => $moduleKey],
                        ['is_enabled_for_company' => true],
                    );
                }
            }

            // Activate default field presets (structured format)
            $defaultFields = static::defaultFieldsFor($jobdomainKey);

            if (!empty($defaultFields)) {
                $fieldConfigs = collect($defaultFields)->keyBy('code');
                $codes = $fieldConfigs->keys()->toArray();

                $definitions = FieldDefinition::whereNull('company_id')
                    ->whereIn('code', $codes)
                    ->whereIn('scope', [FieldDefinition::SCOPE_COMPANY, FieldDefinition::SCOPE_COMPANY_USER])
                    ->get();

                foreach ($definitions as $definition) {
                    $config = $fieldConfigs->get($definition->code);

                    FieldActivation::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'field_definition_id' => $definition->id,
                        ],
                        [
                            'enabled' => true,
                            'required_override' => $config['required'] ?? false,
                            'order' => $config['order'] ?? $definition->default_order ?? 0,
                        ],
                    );
                }
            }

            // Seed default roles from jobdomain (DB, editable via platform UI)
            $defaultRoles = $jobdomain->default_roles ?? [];

            foreach ($defaultRoles as $roleKey => $roleDef) {
                $role = CompanyRole::updateOrCreate(
                    ['company_id' => $company->id, 'key' => $roleKey],
                    [
                        'name' => $roleDef['name'],
                        'is_system' => true,
                        'is_administrative' => $roleDef['is_administrative'] ?? false,
                    ],
                );

                // Resolve bundles → permission keys, then union with direct permissions
                $bundlePermKeys = ModuleRegistry::resolveBundles($roleDef['bundles'] ?? []);
                $directPermKeys = $roleDef['permissions'] ?? [];
                $allPermKeys = array_unique(array_merge($bundlePermKeys, $directPermKeys));

                $permissionIds = CompanyPermission::whereIn('key', $allPermKeys)
                    ->pluck('id')
                    ->toArray();

                $role->syncPermissionsSafe($permissionIds);
            }

            // Refresh the relation
            $company->load('jobdomains');

            return $jobdomain;
        });
    }
}
