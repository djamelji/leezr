<?php

namespace App\Core\Modules;

use App\Core\Events\ModuleDisabled;
use App\Core\Events\ModuleEnabled;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Intelligent module activation/deactivation engine.
 *
 * Replaces CompanyModuleService as the single entry point for module state changes.
 * Manages activation_reasons (source of truth) and syncs company_modules (derived cache).
 *
 * Key behaviors:
 *   - enable(): cascade-activates required modules, each with reason='required'
 *   - disable(): removes only the 'direct' reason, then cleans up orphans
 *   - Orphan = module with 0 remaining activation_reasons → deactivated
 *   - company_modules.is_enabled_for_company is synced from activation_reasons
 */
class ModuleActivationEngine
{
    /**
     * Enable a module for a company (direct activation).
     *
     * Pipeline:
     *   1. Check global enablement
     *   2. Check entitlement
     *   3. Collect transitive requires (DFS)
     *   4. Check all requires are globally enabled + entitled
     *   5. Transaction: add 'direct' reason + cascade-add 'required' reasons + sync cache
     *
     * @return array{success: bool, status: int, data: array}
     */
    public static function enable(Company $company, string $key): array
    {
        if (!ModuleGate::isEnabledGlobally($key)) {
            return [
                'success' => false,
                'status' => 422,
                'data' => ['message' => 'Module is not available globally.'],
            ];
        }

        $entitlement = EntitlementResolver::check($company, $key);

        if (!$entitlement['entitled']) {
            $messages = [
                'plan_required' => 'This module requires a higher plan.',
                'incompatible_jobdomain' => 'This module is not available for your industry.',
                'not_available' => 'This module is not included in your plan.',
            ];

            return [
                'success' => false,
                'status' => 422,
                'data' => [
                    'message' => $messages[$entitlement['reason']] ?? 'Module not available.',
                    'reason' => $entitlement['reason'],
                ],
            ];
        }

        // Collect all transitive requires
        $allRequires = static::collectTransitiveRequires($key);

        // Check all requires are globally enabled + entitled
        foreach ($allRequires as $reqKey) {
            if (!ModuleGate::isEnabledGlobally($reqKey)) {
                return [
                    'success' => false,
                    'status' => 422,
                    'data' => [
                        'message' => "Required module '{$reqKey}' is not available globally.",
                        'missing' => [$reqKey],
                    ],
                ];
            }

            $reqEntitlement = EntitlementResolver::check($company, $reqKey);

            if (!$reqEntitlement['entitled']) {
                return [
                    'success' => false,
                    'status' => 422,
                    'data' => [
                        'message' => "Required module '{$reqKey}' is not available for this company.",
                        'missing' => [$reqKey],
                    ],
                ];
            }
        }

        $activated = [];

        DB::transaction(function () use ($company, $key, $allRequires, &$activated) {
            // 1. Add 'direct' reason for the requested module
            static::addReason($company, $key, CompanyModuleActivationReason::REASON_DIRECT);

            if (!static::wasAlreadyActive($company, $key)) {
                $activated[] = $key;
            }

            // 2. Cascade-activate required modules
            $manifest = ModuleRegistry::definitions()[$key] ?? null;

            if ($manifest) {
                foreach ($manifest->requires as $reqKey) {
                    static::addReason(
                        $company,
                        $reqKey,
                        CompanyModuleActivationReason::REASON_REQUIRED,
                        $key,
                    );

                    if (!static::wasAlreadyActive($company, $reqKey)) {
                        $activated[] = $reqKey;
                    }
                }
            }

            // 3. Also cascade for transitive requires (requires of requires)
            foreach ($allRequires as $reqKey) {
                $reqManifest = ModuleRegistry::definitions()[$reqKey] ?? null;

                if ($reqManifest) {
                    foreach ($reqManifest->requires as $deepReqKey) {
                        static::addReason(
                            $company,
                            $deepReqKey,
                            CompanyModuleActivationReason::REASON_REQUIRED,
                            $reqKey,
                        );
                    }
                }
            }

            // 4. Sync cache for all affected modules
            $affected = array_unique(array_merge([$key], $allRequires));

            foreach ($affected as $moduleKey) {
                static::syncCache($company, $moduleKey);
            }
        });

        // Dispatch events outside transaction
        foreach (array_unique($activated) as $moduleKey) {
            ModuleEnabled::dispatch($company, $moduleKey);
        }

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'message' => 'Module enabled.',
                'activated' => array_unique($activated),
                'modules' => ModuleCatalogReadModel::forCompany($company),
            ],
        ];
    }

    /**
     * Disable a module for a company (remove direct activation).
     *
     * Pipeline:
     *   1. Check module exists and is not core
     *   2. Remove 'direct' reason only
     *   3. Clean up orphans: modules that lost all reasons
     *   4. Cascade-remove 'required' reasons where source is a deactivated module
     *   5. Sync cache for all affected modules
     *
     * @return array{success: bool, status: int, data: array}
     */
    public static function disable(Company $company, string $key): array
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (!$manifest) {
            return [
                'success' => false,
                'status' => 404,
                'data' => ['message' => 'Module not found.'],
            ];
        }

        if ($manifest->type === 'core') {
            return [
                'success' => false,
                'status' => 422,
                'data' => ['message' => 'Core modules cannot be disabled.'],
            ];
        }

        $deactivated = [];

        DB::transaction(function () use ($company, $key, &$deactivated) {
            // 1. Remove 'direct' reason for this module
            static::removeReason($company, $key, CompanyModuleActivationReason::REASON_DIRECT);

            // 2. Clean up orphans (iterative until stable)
            $deactivated = static::cleanupOrphans($company);

            // 3. Sync cache for all affected modules
            static::syncCache($company, $key);

            foreach ($deactivated as $moduleKey) {
                static::syncCache($company, $moduleKey);
            }
        });

        // The requested module may or may not have been deactivated
        // (it could still have other reasons like 'plan' or 'required')
        $wasDeactivated = in_array($key, $deactivated, true)
            || !static::hasAnyReason($company, $key);

        if ($wasDeactivated && !in_array($key, $deactivated, true)) {
            $deactivated[] = $key;
        }

        // Dispatch events outside transaction
        foreach ($deactivated as $moduleKey) {
            ModuleDisabled::dispatch($company, $moduleKey);
        }

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'message' => 'Module disabled.',
                'deactivated' => $deactivated,
                'modules' => ModuleCatalogReadModel::forCompany($company),
            ],
        ];
    }

    /**
     * Collect all transitive requires for a module (DFS, no duplicates).
     *
     * @return string[] Module keys required transitively
     */
    public static function collectTransitiveRequires(string $moduleKey): array
    {
        $visited = [];

        static::dfsRequires($moduleKey, $visited);

        // Remove the module itself from the result
        unset($visited[$moduleKey]);

        return array_keys($visited);
    }

    /**
     * Check if a module has any activation reason.
     */
    public static function hasAnyReason(Company $company, string $moduleKey): bool
    {
        return CompanyModuleActivationReason::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->exists();
    }

    /**
     * Get all activation reasons for a module.
     *
     * @return array<array{reason: string, source_module_key: ?string}>
     */
    public static function reasonsFor(Company $company, string $moduleKey): array
    {
        return CompanyModuleActivationReason::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->get(['reason', 'source_module_key'])
            ->map(fn ($r) => ['reason' => $r->reason, 'source_module_key' => $r->source_module_key])
            ->all();
    }

    // ─── Internal ────────────────────────────────────────────

    private static function addReason(
        Company $company,
        string $moduleKey,
        string $reason,
        ?string $sourceModuleKey = null,
    ): void {
        CompanyModuleActivationReason::firstOrCreate([
            'company_id' => $company->id,
            'module_key' => $moduleKey,
            'reason' => $reason,
            'source_module_key' => $sourceModuleKey,
        ]);
    }

    private static function removeReason(
        Company $company,
        string $moduleKey,
        string $reason,
        ?string $sourceModuleKey = null,
    ): void {
        $query = CompanyModuleActivationReason::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->where('reason', $reason);

        if ($sourceModuleKey !== null) {
            $query->where('source_module_key', $sourceModuleKey);
        } else {
            $query->whereNull('source_module_key');
        }

        $query->delete();
    }

    /**
     * Clean up orphan modules: modules with 0 activation reasons.
     * Iterative: removing a module may orphan modules that depended on it via 'required'.
     *
     * @return string[] Module keys that were deactivated
     */
    private static function cleanupOrphans(Company $company): array
    {
        $deactivated = [];
        $maxIterations = 50; // Safety valve

        for ($i = 0; $i < $maxIterations; $i++) {
            // Find modules that no longer have any activation reason
            $activeModuleKeys = CompanyModuleActivationReason::where('company_id', $company->id)
                ->select('module_key')
                ->distinct()
                ->pluck('module_key')
                ->all();

            // Find 'required' reasons where the source module is no longer active
            $orphanedRequireds = CompanyModuleActivationReason::where('company_id', $company->id)
                ->where('reason', CompanyModuleActivationReason::REASON_REQUIRED)
                ->whereNotNull('source_module_key')
                ->whereNotIn('source_module_key', $activeModuleKeys)
                ->get();

            if ($orphanedRequireds->isEmpty()) {
                break; // No more orphans, stable state reached
            }

            // Remove orphaned 'required' reasons
            foreach ($orphanedRequireds as $orphan) {
                $orphan->delete();
            }

            // Now find modules with 0 reasons left
            $allModulesWithReasons = CompanyModuleActivationReason::where('company_id', $company->id)
                ->select('module_key')
                ->distinct()
                ->pluck('module_key')
                ->all();

            // Modules that had 'required' reasons removed but may now be orphaned
            $affectedKeys = $orphanedRequireds->pluck('module_key')->unique()->all();

            foreach ($affectedKeys as $affectedKey) {
                if (!in_array($affectedKey, $allModulesWithReasons, true)) {
                    $deactivated[] = $affectedKey;
                }
            }
        }

        return $deactivated;
    }

    /**
     * Sync the company_modules cache row from activation_reasons.
     */
    private static function syncCache(Company $company, string $moduleKey): void
    {
        $hasReasons = static::hasAnyReason($company, $moduleKey);

        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $moduleKey],
            ['is_enabled_for_company' => $hasReasons],
        );
    }

    /**
     * Check if a module was already active before this transaction.
     * Uses the cache (company_modules) since reasons haven't been synced yet.
     */
    private static function wasAlreadyActive(Company $company, string $moduleKey): bool
    {
        $cm = CompanyModule::where('company_id', $company->id)
            ->where('module_key', $moduleKey)
            ->first();

        return $cm !== null && $cm->is_enabled_for_company;
    }

    /**
     * DFS traversal of requires graph.
     */
    private static function dfsRequires(string $moduleKey, array &$visited): void
    {
        if (isset($visited[$moduleKey])) {
            return; // Already visited (handles cycles gracefully)
        }

        $visited[$moduleKey] = true;

        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest || empty($manifest->requires)) {
            return;
        }

        foreach ($manifest->requires as $reqKey) {
            static::dfsRequires($reqKey, $visited);
        }
    }
}
