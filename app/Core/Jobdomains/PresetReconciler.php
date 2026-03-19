<?php

namespace App\Core\Jobdomains;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;

/**
 * ADR-375: Preset reconciliation engine.
 *
 * Detects and optionally fixes drift between current company roles/permissions
 * and the canonical preset defined in JobdomainRegistry.
 *
 * Principles:
 * - Dry-run by default (apply=false)
 * - Non-destructive for custom roles (is_system=false → skipped)
 * - Snapshot before apply
 * - Traceable via ReconciliationReport
 */
class PresetReconciler
{
    /**
     * Reconcile a single company against its jobdomain preset.
     */
    public static function reconcile(Company $company, bool $apply = false): ReconciliationReport
    {
        $report = new ReconciliationReport;

        $jobdomainKey = $company->jobdomain_key;

        if (! $jobdomainKey) {
            $report->addWarning("Company #{$company->id} has no jobdomain_key — skipping.");

            return $report;
        }

        $definition = JobdomainRegistry::get($jobdomainKey);

        if (! $definition) {
            $report->addWarning("Jobdomain '{$jobdomainKey}' not found in registry — skipping company #{$company->id}.");

            return $report;
        }

        // ADR-190: Resolve presets with market overlay
        $presets = JobdomainPresetResolver::resolve($jobdomainKey, $company->market_key);
        $presetRoles = $presets->roles;

        if (empty($presetRoles)) {
            $report->addWarning("Jobdomain '{$jobdomainKey}' has no preset roles — skipping company #{$company->id}.");

            return $report;
        }

        // Load all company roles with their permissions
        $existingRoles = $company->roles()->with('permissions:id,key')->get()->keyBy('key');

        // Snapshot before apply
        if ($apply) {
            CompanyPresetSnapshot::capture($company, 'reconcile_apply');
        }

        foreach ($presetRoles as $roleKey => $roleDef) {
            $existingRole = $existingRoles->get($roleKey);

            // Role doesn't exist yet → create it if applying
            if (! $existingRole) {
                if ($apply) {
                    $existingRole = self::createRoleFromPreset($company, $roleKey, $roleDef, $definition);
                }

                $expectedPermKeys = self::resolveExpectedPermissions($roleDef);
                $report->addDrifted(
                    $company->id,
                    $roleKey,
                    $existingRole?->id ?? 0,
                    $expectedPermKeys,
                    [],
                    $apply,
                );

                continue;
            }

            // Custom role (not system) → skip
            if (! $existingRole->is_system) {
                $report->addSkipped($company->id, $roleKey, $existingRole->id, 'Custom role (is_system=false)');

                continue;
            }

            // Compare permissions
            $currentPermKeys = $existingRole->permissions->pluck('key')->sort()->values()->all();
            $expectedPermKeys = self::resolveExpectedPermissions($roleDef);
            sort($expectedPermKeys);

            $missing = array_values(array_diff($expectedPermKeys, $currentPermKeys));
            $extra = array_values(array_diff($currentPermKeys, $expectedPermKeys));

            if (empty($missing) && empty($extra)) {
                // Also check is_administrative and archetype
                $adminDrift = $existingRole->is_administrative !== ($roleDef['is_administrative'] ?? false);
                $archetypeDrift = $existingRole->archetype !== ($roleDef['archetype'] ?? null);

                if (! $adminDrift && ! $archetypeDrift) {
                    $report->addUpToDate($company->id, $roleKey, $existingRole->id);

                    continue;
                }
            }

            if ($apply) {
                self::applyPresetToRole($existingRole, $roleDef, $expectedPermKeys, $definition);
            }

            $report->addDrifted($company->id, $roleKey, $existingRole->id, $missing, $extra, $apply);
        }

        // Check for roles that exist in DB but not in preset (informational warning)
        foreach ($existingRoles as $key => $role) {
            if ($role->is_system && ! isset($presetRoles[$key])) {
                $report->addWarning("Company #{$company->id} has system role '{$key}' not in preset — orphan.");
            }
        }

        return $report;
    }

    /**
     * Reconcile all companies for a given jobdomain.
     */
    public static function reconcileByJobdomain(string $jobdomainKey, bool $apply = false): ReconciliationReport
    {
        $report = new ReconciliationReport;

        $companies = Company::where('jobdomain_key', $jobdomainKey)->get();

        if ($companies->isEmpty()) {
            $report->addWarning("No companies found for jobdomain '{$jobdomainKey}'.");

            return $report;
        }

        foreach ($companies as $company) {
            $subReport = self::reconcile($company, $apply);
            self::mergeReports($report, $subReport);
        }

        return $report;
    }

    /**
     * Reconcile all companies across all jobdomains.
     */
    public static function reconcileAll(bool $apply = false): ReconciliationReport
    {
        $report = new ReconciliationReport;

        $companies = Company::whereNotNull('jobdomain_key')->get();

        foreach ($companies as $company) {
            $subReport = self::reconcile($company, $apply);
            self::mergeReports($report, $subReport);
        }

        return $report;
    }

    /**
     * Resolve the expected permission keys for a role definition.
     */
    private static function resolveExpectedPermissions(array $roleDef): array
    {
        $bundlePermKeys = ModuleRegistry::resolveBundles($roleDef['bundles'] ?? []);
        $directPermKeys = $roleDef['permissions'] ?? [];

        return array_values(array_unique(array_merge($bundlePermKeys, $directPermKeys)));
    }

    /**
     * Create a role from preset definition.
     */
    private static function createRoleFromPreset(Company $company, string $roleKey, array $roleDef, array $jobdomainDef): CompanyRole
    {
        $archetype = $roleDef['archetype'] ?? null;
        $archetypes = $jobdomainDef['archetypes'] ?? [];
        $requiredTags = null;

        if ($archetype && isset($archetypes[$archetype])) {
            $requiredTags = $archetypes[$archetype]['default_tags'] ?? [];
        }

        $role = CompanyRole::create([
            'company_id' => $company->id,
            'key' => $roleKey,
            'name' => $roleDef['name'],
            'is_system' => true,
            'is_administrative' => $roleDef['is_administrative'] ?? false,
            'archetype' => $archetype,
            'required_tags' => $requiredTags,
        ]);

        $expectedPermKeys = self::resolveExpectedPermissions($roleDef);
        $permissionIds = CompanyPermission::whereIn('key', $expectedPermKeys)->pluck('id')->toArray();
        $role->syncPermissionsSafe($permissionIds);

        return $role;
    }

    /**
     * Apply preset to an existing system role.
     */
    private static function applyPresetToRole(CompanyRole $role, array $roleDef, array $expectedPermKeys, array $jobdomainDef): void
    {
        $archetype = $roleDef['archetype'] ?? null;
        $archetypes = $jobdomainDef['archetypes'] ?? [];
        $requiredTags = null;

        if ($archetype && isset($archetypes[$archetype])) {
            $requiredTags = $archetypes[$archetype]['default_tags'] ?? [];
        }

        $role->update([
            'is_administrative' => $roleDef['is_administrative'] ?? false,
            'archetype' => $archetype,
            'required_tags' => $requiredTags,
        ]);

        $permissionIds = CompanyPermission::whereIn('key', $expectedPermKeys)->pluck('id')->toArray();
        $role->syncPermissionsSafe($permissionIds);
    }

    /**
     * Merge a sub-report into a main report.
     */
    private static function mergeReports(ReconciliationReport $main, ReconciliationReport $sub): void
    {
        $main->upToDate = array_merge($main->upToDate, $sub->upToDate);
        $main->drifted = array_merge($main->drifted, $sub->drifted);
        $main->skipped = array_merge($main->skipped, $sub->skipped);
        $main->warnings = array_merge($main->warnings, $sub->warnings);
    }
}
