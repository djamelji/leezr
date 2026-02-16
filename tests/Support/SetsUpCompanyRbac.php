<?php

namespace Tests\Support;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;

/**
 * Test helper trait — seeds company RBAC permissions + roles.
 * Call $this->setUpCompanyRbac($company) in setUp() after creating the company.
 */
trait SetsUpCompanyRbac
{
    /**
     * Sync the permission catalog and create a full-access "admin" role for the company.
     * Returns the admin CompanyRole.
     */
    protected function setUpCompanyRbac(Company $company): CompanyRole
    {
        CompanyPermissionCatalog::sync();

        $adminRole = CompanyRole::updateOrCreate(
            ['company_id' => $company->id, 'key' => 'admin'],
            ['name' => 'Administrator', 'is_system' => true, 'is_administrative' => true],
        );

        $adminRole->permissions()->sync(
            \App\Company\RBAC\CompanyPermission::pluck('id')->toArray(),
        );

        return $adminRole;
    }

    /**
     * Assign a company role to a membership.
     */
    protected function assignCompanyRole(Membership $membership, CompanyRole $role): void
    {
        $membership->update(['company_role_id' => $role->id]);
    }
}
