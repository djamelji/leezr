<?php

namespace App\Core\Auth\ReadModels;

use App\Core\Auth\WorkspaceResolver;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\User;

class UserCompaniesReadModel
{
    /**
     * Build the list of companies a user belongs to,
     * with RBAC role, permissions, plan key, and admin status.
     */
    public static function forUser(User $user): array
    {
        $memberships = $user->memberships()
            ->with('companyRole.permissions', 'company')
            ->get();

        return $memberships->map(function ($membership) {
            $isAdministrative = $membership->isAdmin();

            $data = [
                'id' => $membership->company->id,
                'name' => $membership->company->name,
                'slug' => $membership->company->slug,
                'role' => $membership->role,
                'is_administrative' => $isAdministrative,
                'plan_key' => CompanyEntitlements::planKey($membership->company),
            ];

            if ($membership->companyRole) {
                $archetype = $membership->companyRole->archetype;
                $data['company_role'] = [
                    'id' => $membership->companyRole->id,
                    'key' => $membership->companyRole->key,
                    'name' => $membership->companyRole->name,
                    'is_administrative' => (bool) $membership->companyRole->is_administrative,
                    'archetype' => $archetype,
                    'permissions' => $membership->companyRole->permissions->pluck('key')->values(),
                ];
                $data['workspace'] = WorkspaceResolver::resolve($archetype);
            } else {
                $data['company_role'] = null;
                $data['workspace'] = 'dashboard';
            }

            return $data;
        })->all();
    }
}
