<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleRegistry;

/**
 * ADR-213: Jobdomain update with automatic module dependency resolution.
 *
 * When default_modules changes:
 *   - ON: auto-expands with transitive requires (e.g. tracking → adds shipments)
 *   - OFF: cascade-removes modules whose requires are no longer met
 *
 * Returns resolved jobdomain + auto_added/auto_removed for admin feedback.
 */
class UpdateJobdomainUseCase
{
    /**
     * @return array{jobdomain: Jobdomain, auto_added: string[], auto_removed: string[]}
     */
    public function execute(UpdateJobdomainData $data): array
    {
        $jobdomain = Jobdomain::findOrFail($data->id);

        if (isset($data->attributes['default_fields'])) {
            JobdomainPresetValidator::validateDefaultFields($data->attributes['default_fields']);
        }

        if (isset($data->attributes['default_roles'])) {
            JobdomainPresetValidator::validateDefaultRoles($data->attributes['default_roles']);
        }

        if (isset($data->attributes['default_documents'])) {
            JobdomainPresetValidator::validateDefaultDocuments($data->attributes['default_documents']);
        }

        // ADR-213: Auto-resolve module dependencies
        $autoAdded = [];
        $autoRemoved = [];
        $attributes = $data->attributes;

        if (isset($attributes['default_modules'])) {
            $oldDefaults = $jobdomain->default_modules ?? [];
            $resolved = static::resolveModuleDependencies($oldDefaults, $attributes['default_modules']);
            $attributes['default_modules'] = $resolved['modules'];
            $autoAdded = $resolved['auto_added'];
            $autoRemoved = $resolved['auto_removed'];
        }

        $jobdomain->update($attributes);
        $jobdomain->loadCount('companies');

        return [
            'jobdomain' => $jobdomain,
            'auto_added' => $autoAdded,
            'auto_removed' => $autoRemoved,
        ];
    }

    /**
     * Resolve module dependencies for a defaults change.
     *
     * 1. Expand new additions with transitive requires (skip core — always active)
     * 2. Cascade-remove modules whose requires are no longer met
     *
     * @return array{modules: string[], auto_added: string[], auto_removed: string[]}
     */
    private static function resolveModuleDependencies(array $oldDefaults, array $newDefaults): array
    {
        $autoAdded = [];
        $autoRemoved = [];
        $definitions = ModuleRegistry::definitions();

        // Step 1: Expand newly added modules with their transitive requires
        $added = array_diff($newDefaults, $oldDefaults);

        foreach ($added as $moduleKey) {
            $requires = ModuleActivationEngine::collectTransitiveRequires($moduleKey);

            foreach ($requires as $reqKey) {
                // Core modules don't need to be in defaults — always active
                $reqManifest = $definitions[$reqKey] ?? null;
                if ($reqManifest && $reqManifest->type === 'core') {
                    continue;
                }

                if (! in_array($reqKey, $newDefaults, true)) {
                    $newDefaults[] = $reqKey;
                    $autoAdded[] = $reqKey;
                }
            }
        }

        // Step 2: Cascade-remove modules whose requires are no longer met
        $maxIterations = 50;

        for ($i = 0; $i < $maxIterations; $i++) {
            $toRemove = [];

            foreach ($newDefaults as $moduleKey) {
                $manifest = $definitions[$moduleKey] ?? null;

                if (! $manifest || empty($manifest->requires)) {
                    continue;
                }

                foreach ($manifest->requires as $reqKey) {
                    $reqManifest = $definitions[$reqKey] ?? null;

                    // Core modules are always available
                    if ($reqManifest && $reqManifest->type === 'core') {
                        continue;
                    }

                    // Required module not in defaults → this module must be removed
                    if (! in_array($reqKey, $newDefaults, true)) {
                        $toRemove[] = $moduleKey;
                        break;
                    }
                }
            }

            if (empty($toRemove)) {
                break;
            }

            foreach ($toRemove as $key) {
                $newDefaults = array_values(array_filter($newDefaults, fn ($k) => $k !== $key));

                // Only track as auto-removed if admin didn't explicitly remove it
                if (in_array($key, $oldDefaults, true) && ! in_array($key, array_diff($oldDefaults, $newDefaults), true)) {
                    $autoRemoved[] = $key;
                }
            }
        }

        return [
            'modules' => array_values(array_unique($newDefaults)),
            'auto_added' => array_values(array_unique($autoAdded)),
            'auto_removed' => array_values(array_unique($autoRemoved)),
        ];
    }
}
