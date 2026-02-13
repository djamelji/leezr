<?php

namespace App\Core\Jobdomains;

use App\Core\Models\Company;

/**
 * Read model for jobdomain catalog and company assignment.
 */
class JobdomainCatalogReadModel
{
    /**
     * List all available jobdomains.
     */
    public static function all(): array
    {
        return Jobdomain::where('is_active', true)
            ->orderBy('label')
            ->get()
            ->map(fn (Jobdomain $jd) => [
                'key' => $jd->key,
                'label' => $jd->label,
                'description' => $jd->description,
            ])
            ->all();
    }

    /**
     * Get the full resolved profile for a company's jobdomain.
     */
    public static function forCompany(Company $company): array
    {
        $jobdomain = JobdomainGate::resolveForCompany($company);

        if (!$jobdomain) {
            return [
                'assigned' => false,
                'jobdomain' => null,
                'allow_custom_fields' => false,
                'profile' => [
                    'landing_route' => '/',
                    'nav_profile' => null,
                    'default_modules' => [],
                ],
            ];
        }

        $definition = JobdomainRegistry::get($jobdomain->key);

        return [
            'assigned' => true,
            'jobdomain' => [
                'key' => $jobdomain->key,
                'label' => $jobdomain->label,
                'description' => $jobdomain->description,
            ],
            'allow_custom_fields' => (bool) $jobdomain->allow_custom_fields,
            'profile' => [
                'landing_route' => $definition['landing_route'] ?? '/',
                'nav_profile' => $definition['nav_profile'] ?? null,
                'default_modules' => $definition['default_modules'] ?? [],
            ],
        ];
    }
}
