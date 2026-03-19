<?php

namespace App\Core\Jobdomains;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Central service for jobdomain resolution.
 * All jobdomain-dependent logic passes through here — no if(jobdomain===...) elsewhere.
 */
class JobdomainGate
{
    /**
     * ADR-167a: Jobdomain is always present — structural invariant.
     */
    public static function resolveForCompany(Company $company): Jobdomain
    {
        return $company->jobdomain;
    }

    /**
     * Get the landing route for a company's jobdomain.
     */
    public static function landingRouteFor(Company $company): string
    {
        $definition = JobdomainRegistry::get($company->jobdomain_key);

        return $definition['landing_route'] ?? '/';
    }

    /**
     * Get the nav profile key for a company's jobdomain.
     */
    public static function navProfileFor(Company $company): ?string
    {
        $definition = JobdomainRegistry::get($company->jobdomain_key);

        return $definition['nav_profile'] ?? null;
    }

    /**
     * Get the default module keys for a jobdomain.
     * Reads from DB (editable via platform admin), falls back to Registry.
     */
    public static function defaultModulesFor(string $jobdomainKey): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();

        if ($jobdomain && !empty($jobdomain->default_modules)) {
            return $jobdomain->default_modules;
        }

        $definition = JobdomainRegistry::get($jobdomainKey);

        return $definition['default_modules'] ?? [];
    }

    /**
     * Get the default document type codes for a jobdomain.
     * Returns structured array: [{code, order}, ...]
     * Reads from DB (editable via platform admin), falls back to Registry.
     */
    public static function defaultDocumentsFor(string $jobdomainKey): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();

        if ($jobdomain && $jobdomain->default_documents !== null) {
            return $jobdomain->default_documents;
        }

        $definition = JobdomainRegistry::get($jobdomainKey);

        return $definition['default_documents'] ?? [];
    }

    /**
     * Get the default field presets for a jobdomain.
     * Returns structured array: [{code, order}, ...]
     * Reads from DB (editable via platform admin), falls back to Registry.
     */
    public static function defaultFieldsFor(string $jobdomainKey): array
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)->first();

        if ($jobdomain && !empty($jobdomain->default_fields)) {
            return $jobdomain->default_fields;
        }

        $definition = JobdomainRegistry::get($jobdomainKey);

        return $definition['default_fields'] ?? [];
    }

    /**
     * Assign a jobdomain to a company and activate default modules + field presets.
     * Uses a transaction to ensure atomicity.
     */
    public static function assignToCompany(Company $company, string $jobdomainKey): Jobdomain
    {
        $jobdomain = Jobdomain::where('key', $jobdomainKey)
            ->where('is_active', true)
            ->firstOrFail();

        return DB::transaction(function () use ($company, $jobdomain, $jobdomainKey) {
            // ADR-167a: Set the direct column (source of truth)
            $company->update(['jobdomain_key' => $jobdomainKey]);

            // Assign pivot (backward compat — will be removed in ADR-167b)
            $company->jobdomains()->sync([$jobdomain->id]);

            // ADR-190: Resolve presets with market overlay
            $presets = JobdomainPresetResolver::resolve($jobdomainKey, $company->market_key);

            // Activate default modules + auto-resolve dependencies (ADR-211)
            $defaultModules = $presets->modules;

            // Expand with transitive requires so dependencies are auto-activated
            $allRequired = [];

            foreach ($defaultModules as $moduleKey) {
                foreach (ModuleActivationEngine::collectTransitiveRequires($moduleKey) as $reqKey) {
                    if (! in_array($reqKey, $defaultModules, true)) {
                        $allRequired[$reqKey][] = $moduleKey;
                    }
                }
            }

            // Activate explicit defaults with REASON_DIRECT
            foreach ($defaultModules as $moduleKey) {
                if (ModuleGate::isEnabledGlobally($moduleKey)) {
                    CompanyModuleActivationReason::firstOrCreate([
                        'company_id' => $company->id,
                        'module_key' => $moduleKey,
                        'reason' => CompanyModuleActivationReason::REASON_DIRECT,
                        'source_module_key' => null,
                    ]);

                    CompanyModule::updateOrCreate(
                        ['company_id' => $company->id, 'module_key' => $moduleKey],
                        ['is_enabled_for_company' => true],
                    );
                }
            }

            // Activate auto-resolved dependencies with REASON_REQUIRED
            foreach ($allRequired as $reqKey => $sourceKeys) {
                if (ModuleGate::isEnabledGlobally($reqKey)) {
                    foreach ($sourceKeys as $sourceKey) {
                        CompanyModuleActivationReason::firstOrCreate([
                            'company_id' => $company->id,
                            'module_key' => $reqKey,
                            'reason' => CompanyModuleActivationReason::REASON_REQUIRED,
                            'source_module_key' => $sourceKey,
                        ]);
                    }

                    CompanyModule::updateOrCreate(
                        ['company_id' => $company->id, 'module_key' => $reqKey],
                        ['is_enabled_for_company' => true],
                    );
                }
            }

            // Activate default field presets (structured format)
            $defaultFields = $presets->fields;

            if (!empty($defaultFields)) {
                $fieldConfigs = collect($defaultFields)->keyBy('code');
                $codes = $fieldConfigs->keys()->toArray();

                $definitions = FieldDefinition::whereNull('company_id')
                    ->whereIn('code', $codes)
                    ->whereIn('scope', [FieldDefinition::SCOPE_COMPANY, FieldDefinition::SCOPE_COMPANY_USER])
                    ->get();

                foreach ($definitions as $definition) {
                    $config = $fieldConfigs->get($definition->code);

                    FieldActivation::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'field_definition_id' => $definition->id,
                        ],
                        [
                            'enabled' => true,
                            // ADR-169: catalog handles mandatory via required_by_*, preset only activates
                            'required_override' => false,
                            'order' => $config['order'] ?? $definition->default_order ?? 0,
                        ],
                    );
                }
            }

            // ADR-169 Phase 3: Activate default document types
            $defaultDocuments = $presets->documents;

            if (!empty($defaultDocuments)) {
                $docConfigs = collect($defaultDocuments)->keyBy('code');
                $docCodes = $docConfigs->keys()->toArray();

                $docTypes = DocumentType::whereIn('code', $docCodes)
                    ->whereIn('scope', [DocumentType::SCOPE_COMPANY, DocumentType::SCOPE_COMPANY_USER])
                    ->get();

                foreach ($docTypes as $docType) {
                    $config = $docConfigs->get($docType->code);

                    DocumentTypeActivation::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'document_type_id' => $docType->id,
                        ],
                        [
                            'enabled' => true,
                            'required_override' => false,
                            'order' => $config['order'] ?? $docType->default_order ?? 0,
                        ],
                    );
                }
            }

            // Seed default roles from jobdomain (DB, editable via platform UI)
            $defaultRoles = $presets->roles;

            // ADR-170: Resolve archetype default_tags from registry
            $registryDef = JobdomainRegistry::get($jobdomainKey);
            $archetypes = $registryDef['archetypes'] ?? [];

            foreach ($defaultRoles as $roleKey => $roleDef) {
                // ADR-170: Resolve required_tags from archetype's default_tags
                $archetype = $roleDef['archetype'] ?? null;
                $requiredTags = null;
                if ($archetype && isset($archetypes[$archetype])) {
                    $requiredTags = $archetypes[$archetype]['default_tags'] ?? [];
                }

                $role = CompanyRole::updateOrCreate(
                    ['company_id' => $company->id, 'key' => $roleKey],
                    [
                        'name' => $roleDef['name'],
                        'is_system' => true,
                        'is_administrative' => $roleDef['is_administrative'] ?? false,
                        'archetype' => $archetype,
                        'required_tags' => $requiredTags,
                    ],
                );

                // Resolve bundles → permission keys, then union with direct permissions
                $bundlePermKeys = ModuleRegistry::resolveBundles($roleDef['bundles'] ?? []);
                $directPermKeys = $roleDef['permissions'] ?? [];
                $allPermKeys = array_unique(array_merge($bundlePermKeys, $directPermKeys));

                $permissionIds = CompanyPermission::whereIn('key', $allPermKeys)
                    ->pluck('id')
                    ->toArray();

                $role->syncPermissionsSafe($permissionIds);

                // ADR-164: Seed field_config from jobdomain role definition
                // Only populate if field_config is currently null (don't overwrite company customizations)
                $roleFieldConfig = $roleDef['fields'] ?? null;
                if ($roleFieldConfig !== null && $role->field_config === null) {
                    $role->update(['field_config' => $roleFieldConfig]);
                }

                // ADR-170 Phase 3: Seed doc_config from jobdomain role definition
                // Same pattern: only populate if currently null
                $roleDocConfig = $roleDef['doc_config'] ?? null;
                if ($roleDocConfig !== null && $role->doc_config === null) {
                    $role->update(['doc_config' => $roleDocConfig]);
                }
            }

            // Clone jobdomain dashboard defaults as company base layout (ADR-149, ADR-327)
            // This provides the ideal default layout for each trade/jobdomain.
            // The smart default builder (frontend) is only a fallback when no jobdomain default exists.
            $dashboardDefault = \App\Modules\Dashboard\JobdomainDashboardDefault::where('jobdomain_id', $jobdomain->id)->first();

            if ($dashboardDefault && !\App\Modules\Dashboard\CompanyDashboardLayout::where('company_id', $company->id)->whereNull('user_id')->whereNull('company_role_id')->exists()) {
                \App\Modules\Dashboard\CompanyDashboardLayout::create([
                    'company_id' => $company->id,
                    'user_id' => null,
                    'layout_json' => $dashboardDefault->layout_json,
                ]);
            }

            // ADR-357: Seed per-role dashboard layouts from jobdomain role definitions
            foreach ($defaultRoles as $roleKey => $roleDef) {
                if (empty($roleDef['dashboard_widgets'])) {
                    continue;
                }

                $role = CompanyRole::where('company_id', $company->id)->where('key', $roleKey)->first();
                if (!$role) {
                    continue;
                }

                $tiles = self::buildLayoutFromWidgetKeys($roleDef['dashboard_widgets']);

                \App\Modules\Dashboard\CompanyDashboardLayout::updateOrCreate(
                    ['company_id' => $company->id, 'user_id' => null, 'company_role_id' => $role->id],
                    ['layout_json' => $tiles]
                );
            }

            // ADR-375: Snapshot roles + permissions at registration time
            CompanyPresetSnapshot::capture($company, 'registration');

            // Refresh the relation
            $company->load('jobdomains');

            return $jobdomain;
        });
    }

    /**
     * ADR-357: Build a simple layout array from widget keys.
     * Stacks widgets vertically, 12 columns wide, 4 rows high.
     *
     * @param  string[]  $widgetKeys
     * @return array<array{key: string, x: int, y: int, w: int, h: int, scope: string, config: array}>
     */
    private static function buildLayoutFromWidgetKeys(array $widgetKeys): array
    {
        $tiles = [];
        $y = 0;

        foreach ($widgetKeys as $key) {
            $widget = \App\Modules\Dashboard\DashboardWidgetRegistry::find($key);
            $layout = $widget?->layout() ?? [];

            $w = $layout['default_w'] ?? 4;
            $h = $layout['default_h'] ?? 4;

            $tiles[] = [
                'key' => $key,
                'x' => 0,
                'y' => $y,
                'w' => $w,
                'h' => $h,
                'scope' => 'company',
                'config' => [],
            ];

            $y += $h;
        }

        return $tiles;
    }
}
