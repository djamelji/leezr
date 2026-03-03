<?php

namespace App\Core\Auth\ReadModels;

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
                $data['company_role'] = [
                    'id' => $membership->companyRole->id,
                    'key' => $membership->companyRole->key,
                    'name' => $membership->companyRole->name,
                    'is_administrative' => (bool) $membership->companyRole->is_administrative,
                    'permissions' => $membership->companyRole->permissions->pluck('key')->values(),
                ];
            } else {
                $data['company_role'] = null;
            }

            return $data;
        })->all();
    }
}
