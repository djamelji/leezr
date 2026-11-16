<?php

namespace App\Modules\Workforce\Leave;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleReadiness;
use App\Core\Workforce\LeaveType;

/**
 * ADR-804 — Congés. REQUIRED : access decided, at least one ENABLED leave type (types are seeded at activation
 * by SeedLeaveTypesOnModuleEnabled ; the company reviews them). RECOMMENDED : a role other than the owner can
 * approve. The approval « circuit » is what the engine really does : per leave type `requires_approval`, approved
 * by a holder of `workforce.leave_approve` — nothing else is claimed.
 */
final class LeaveSetupReadiness implements ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'leave_type_enabled', 'ok' => LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->enabled()->exists()],
            ['key' => 'approver_assigned', 'required' => false, 'ok' => CompanyRole::query()->where('company_id', $company->id)->where('key', '!=', 'owner')
                ->whereHas('permissions', fn ($q) => $q->where('key', 'workforce.leave_approve'))->exists()],
        ];
    }

    /** Mandatory settings = the enabled leave types and whether each requires approval. */
    public function settingsFingerprint(Company $company): ?string
    {
        return sha1(json_encode(LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->enabled()
            ->orderBy('code')->get(['code', 'requires_approval', 'is_paid'])->map(fn ($t) => [$t->code, (bool) $t->requires_approval, (bool) $t->is_paid])->all()));
    }
}
