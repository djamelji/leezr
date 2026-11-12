<?php

namespace App\Modules\Core\Members\ReadModels;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;

/**
 * ADR-768 (M7 LOT 2) — the members directory (`GET /company/members`), by FACET.
 *
 *  - `members.view` : the full directory (account e-mail, status, profile completeness, HR link,
 *    quota) — unchanged payload.
 *  - a SELECTOR-only caller (`documents.manage`, `shipments.assign` — allowed on the route because
 *    their own screens need a person picker) receives the MINIMUM needed to pick a person : ids,
 *    name and role label. No e-mail, no account status, no completeness, no HR link, no quota.
 *
 * The route permission lets a caller in ; this read model decides what each facet may read.
 */
class MemberDirectoryReadModel
{
    public static function list(Company $company, bool $full): array
    {
        $members = $company->memberships()
            ->with(['user:id,first_name,last_name,email,avatar,password_set_at', 'companyRole:id,key,name'])
            ->get();

        if (! $full) {
            return [
                'members' => $members->map(fn (Membership $m) => [
                    'id' => $m->id,
                    'user' => $m->user->only('id', 'first_name', 'last_name', 'display_name'),
                    'role' => $m->role,
                    'company_role' => $m->companyRole ? $m->companyRole->only('id', 'key', 'name') : null,
                ])->values(),
                'member_count' => $members->count(),
            ];
        }

        // ADR-168b: bulk completeness (5 queries, no N+1)
        $completeness = CompanyUserProfileReadModel::bulkCompleteness($company, $members);

        // P3-A (ADR-686): mark which accounts are also employees. Light batch read via the user_id
        // bridge, guarded by the workforce module being active (no hard coupling).
        $employeeByUser = [];
        if (ModuleGate::isActive($company, 'workforce')) {
            $employeeByUser = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNotNull('user_id')
                ->pluck('id', 'user_id')->all();
        }

        return [
            'members' => $members->map(fn (Membership $m) => [
                'id' => $m->id,
                'user' => $m->user->only('id', 'first_name', 'last_name', 'display_name', 'email', 'avatar', 'status'),
                'role' => $m->role,
                'company_role' => $m->companyRole ? $m->companyRole->only('id', 'key', 'name') : null,
                'created_at' => $m->created_at,
                'profile_completeness' => $completeness[$m->id] ?? ['filled' => 0, 'total' => 0, 'complete' => true],
                'employee_id' => $employeeByUser[$m->user_id] ?? null,
                'is_employee' => isset($employeeByUser[$m->user_id]),
            ])->values(),
            'member_count' => $members->count(),
            'member_limit' => CompanyEntitlements::memberLimit($company),
        ];
    }
}
