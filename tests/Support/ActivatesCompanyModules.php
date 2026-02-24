<?php

namespace Tests\Support;

use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;

/**
 * Activates all company-scope modules for a given company.
 *
 * Required by any test hitting routes gated with
 * company.access:use-module,{moduleKey}.
 */
trait ActivatesCompanyModules
{
    protected function activateCompanyModules(Company $company): void
    {
        ModuleRegistry::sync();

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            CompanyModule::firstOrCreate(
                ['company_id' => $company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }
    }
}
