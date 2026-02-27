<?php

namespace App\Core\RBAC;

use App\Company\RBAC\CompanyPermission;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformPermission;

/**
 * Unified permission catalog builder (ADR-132).
 *
 * Produces the same enriched JSON shape for both company and platform scopes.
 * Replaces inline enrichment previously in CompanyRoleController::permissionCatalog().
 */
class PermissionCatalogBuilder
{
    /**
     * Build the enriched permission catalog for a given scope.
     *
     * @param  'company'|'admin'  $scope
     * @param  Company|null       $company  Required for company scope (module activation check)
     * @return array{permissions: array, modules: array}
     */
    public static function build(string $scope, ?Company $company = null): array
    {
        $modules = collect(ModuleRegistry::forScope($scope));

        $moduleNames = $modules->mapWithKeys(fn ($m, $key) => [$key => $m->name]);
        $moduleDescriptions = $modules->mapWithKeys(fn ($m, $key) => [$key => $m->description]);
        $moduleIcons = $modules->mapWithKeys(fn ($m, $key) => [
            $key => collect($m->capabilities->navItems)->first()['icon'] ?? $m->iconRef,
        ]);

        // Build hint lookup from module permission definitions
        $hints = [];
        foreach ($modules as $modKey => $manifest) {
            foreach ($manifest->permissions as $perm) {
                if (isset($perm['hint'])) {
                    $hints[$perm['key']] = $perm['hint'];
                }
            }
        }

        // Load permissions from the correct table
        $permissionModel = $scope === 'admin' ? PlatformPermission::class : CompanyPermission::class;

        $permissions = $permissionModel::orderBy('module_key')
            ->orderBy('key')
            ->get(['id', 'key', 'label', 'module_key', 'is_admin'])
            ->map(function ($p) use ($scope, $company, $moduleNames, $moduleDescriptions, $hints) {
                $isCore = str_starts_with($p->module_key, 'core.');
                $moduleActive = $scope === 'admin'
                    ? true  // platform modules always active
                    : ($isCore || ModuleGate::isActive($company, $p->module_key));

                return array_merge($p->toArray(), [
                    'module_name' => $moduleNames[$p->module_key] ?? $p->module_key,
                    'module_description' => $moduleDescriptions[$p->module_key] ?? '',
                    'hint' => $hints[$p->key] ?? '',
                    'module_active' => $moduleActive,
                ]);
            });

        // Build key→id lookup for resolving bundles
        $keyToId = $permissions->pluck('id', 'key');

        // Build module list with bundles (capabilities)
        $moduleList = [];
        foreach ($modules as $modKey => $manifest) {
            $isCore = str_starts_with($modKey, 'core.');
            $moduleActive = $scope === 'admin'
                ? true
                : ($isCore || ModuleGate::isActive($company, $modKey));

            $bundles = [];
            foreach ($manifest->bundles as $bundle) {
                $permissionIds = collect($bundle['permissions'])
                    ->map(fn ($key) => $keyToId[$key] ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if (empty($permissionIds)) {
                    continue;
                }

                $bundles[] = [
                    'key' => $bundle['key'],
                    'label' => $bundle['label'],
                    'hint' => $bundle['hint'] ?? '',
                    'is_admin' => $bundle['is_admin'] ?? false,
                    'permissions' => $bundle['permissions'],
                    'permission_ids' => $permissionIds,
                ];
            }

            $moduleList[] = [
                'module_key' => $modKey,
                'module_name' => $moduleNames[$modKey] ?? $modKey,
                'module_description' => $moduleDescriptions[$modKey] ?? '',
                'module_icon' => $moduleIcons[$modKey] ?? 'tabler-puzzle',
                'module_active' => $moduleActive,
                'is_core' => $isCore,
                'capabilities' => $bundles,
            ];
        }

        return [
            'permissions' => $permissions,
            'modules' => $moduleList,
        ];
    }
}
