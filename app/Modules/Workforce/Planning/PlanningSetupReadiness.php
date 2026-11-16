<?php

namespace App\Modules\Workforce\Planning;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleReadiness;

/**
 * ADR-804 — Planning. REQUIRED : access decided (planning has no mandatory setting of its own : templates are
 * optional). RECOMMENDED : the working-time framework is confirmed in Workforce (the rules applied when a shift is
 * saved belong to Workforce — read, never duplicated here) and a role other than the owner can publish.
 */
final class PlanningSetupReadiness implements ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'workforce_framework', 'required' => false, 'ok' => $company->convention_confirmed_at !== null],
            ['key' => 'publisher_assigned', 'required' => false, 'ok' => CompanyRole::query()->where('company_id', $company->id)->where('key', '!=', 'owner')
                ->whereHas('permissions', fn ($q) => $q->where('key', 'workforce.planning_publish'))->exists()],
        ];
    }

    public function settingsFingerprint(Company $company): ?string
    {
        return null; // no mandatory setting of its own
    }
}
