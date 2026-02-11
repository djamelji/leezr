<?php

namespace App\Core\Jobdomains;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleGate;
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
     */
    public static function defaultModulesFor(string $jobdomainKey): array
    {
        $definition = JobdomainRegistry::get($jobdomainKey);

        return $definition['default_modules'] ?? [];
    }

    /**
     * Assign a jobdomain to a company and activate default modules.
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

            // Refresh the relation
            $company->load('jobdomains');

            return $jobdomain;
        });
    }
}
