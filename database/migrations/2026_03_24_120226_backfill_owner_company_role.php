<?php

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-390: Backfill owner CompanyRole for existing companies.
 *
 * Each company with a jobdomain gets the 'owner' role from the preset,
 * with full permissions synced and field/doc config applied.
 * The owner membership is then assigned to this role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = Company::whereNotNull('jobdomain_key')->get();

        foreach ($companies as $company) {
            $definition = JobdomainRegistry::get($company->jobdomain_key);

            if (! $definition) {
                continue;
            }

            $roleDef = $definition['default_roles']['owner'] ?? null;

            if (! $roleDef) {
                continue;
            }

            // Resolve archetype tags
            $archetypes = $definition['archetypes'] ?? [];
            $archetype = $roleDef['archetype'] ?? null;
            $requiredTags = null;

            if ($archetype && isset($archetypes[$archetype])) {
                $requiredTags = $archetypes[$archetype]['default_tags'] ?? [];
            }

            // Create or update the owner CompanyRole
            $role = CompanyRole::updateOrCreate(
                ['company_id' => $company->id, 'key' => 'owner'],
                [
                    'name' => $roleDef['name'],
                    'is_system' => true,
                    'is_administrative' => $roleDef['is_administrative'] ?? true,
                    'archetype' => $archetype,
                    'required_tags' => $requiredTags,
                ],
            );

            // Sync permissions from bundles
            $bundlePermKeys = ModuleRegistry::resolveBundles($roleDef['bundles'] ?? []);
            $directPermKeys = $roleDef['permissions'] ?? [];
            $allPermKeys = array_unique(array_merge($bundlePermKeys, $directPermKeys));

            $permissionIds = CompanyPermission::whereIn('key', $allPermKeys)
                ->pluck('id')
                ->toArray();

            $role->syncPermissionsSafe($permissionIds);

            // Apply field_config if not yet set
            if ($role->field_config === null && ! empty($roleDef['fields'])) {
                $role->update(['field_config' => $roleDef['fields']]);
            }

            // Apply doc_config if not yet set
            if ($role->doc_config === null && ! empty($roleDef['doc_config'])) {
                $role->update(['doc_config' => $roleDef['doc_config']]);
            }

            // Assign owner membership to the owner CompanyRole
            Membership::where('company_id', $company->id)
                ->where('role', 'owner')
                ->whereNull('company_role_id')
                ->update(['company_role_id' => $role->id]);
        }
    }

    public function down(): void
    {
        // Remove owner CompanyRole assignment from owner memberships
        $ownerRoleIds = CompanyRole::where('key', 'owner')
            ->where('is_system', true)
            ->pluck('id');

        Membership::where('role', 'owner')
            ->whereIn('company_role_id', $ownerRoleIds)
            ->update(['company_role_id' => null]);

        // Delete the owner CompanyRoles
        CompanyRole::where('key', 'owner')
            ->where('is_system', true)
            ->delete();
    }
};
