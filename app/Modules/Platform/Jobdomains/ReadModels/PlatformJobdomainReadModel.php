<?php

namespace App\Modules\Platform\Jobdomains\ReadModels;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;

class PlatformJobdomainReadModel
{
    /**
     * Catalog listing: all jobdomains with company counts.
     */
    public static function catalog(): array
    {
        return Jobdomain::withCount('companies')->get()->toArray();
    }

    /**
     * Full detail view for a single jobdomain.
     * Aggregates: fields, modules/bundles, mandatory codes, document presets.
     */
    public static function detail(int $id): array
    {
        $jobdomain = Jobdomain::withCount('companies')->findOrFail($id);

        $fieldDefinitions = FieldDefinition::whereNull('company_id')
            ->whereIn('scope', [
                FieldDefinition::SCOPE_COMPANY,
                FieldDefinition::SCOPE_COMPANY_USER,
            ])->orderBy('default_order')->get();

        $moduleBundles = self::buildModuleBundles();

        $catalogFields = FieldDefinitionCatalog::all();
        $mandatoryFieldCodes = self::resolveMandatoryFieldCodes($catalogFields, $jobdomain->key);
        $mandatoryByRole = self::resolveMandatoryByRole($catalogFields, $jobdomain->default_roles ?? []);

        $documentPresets = self::buildDocumentPresets($jobdomain);

        // Hydrate default_documents when DB is null (registry fallback)
        $defaultDocs = JobdomainGate::defaultDocumentsFor($jobdomain->key);
        if ($jobdomain->default_documents === null && !empty($defaultDocs)) {
            $jobdomain->setAttribute('default_documents', $defaultDocs);
        }

        return [
            'jobdomain' => $jobdomain,
            'field_definitions' => $fieldDefinitions,
            'permission_catalog' => CompanyPermissionCatalog::all(),
            'module_bundles' => $moduleBundles,
            'mandatory_field_codes' => $mandatoryFieldCodes,
            'mandatory_by_role' => $mandatoryByRole,
            'document_presets' => $documentPresets,
        ];
    }

    /**
     * Build module bundles for the platform role template UI (company-scope only).
     * Applies display overrides from platform_modules DB rows.
     */
    private static function buildModuleBundles(): array
    {
        $modules = collect(ModuleRegistry::forScope('company'));
        $platformOverrides = PlatformModule::all()->keyBy('key');
        $result = [];

        foreach ($modules as $modKey => $manifest) {
            $pm = $platformOverrides->get($modKey);

            $bundles = [];
            foreach ($manifest->bundles as $bundle) {
                $bundles[] = [
                    'key' => $bundle['key'],
                    'label' => $bundle['label'],
                    'hint' => $bundle['hint'] ?? '',
                    'is_admin' => $bundle['is_admin'] ?? false,
                    'permissions' => $bundle['permissions'],
                ];
            }

            $result[] = [
                'module_key' => $modKey,
                'module_name' => $pm?->display_name_override ?? $manifest->name,
                'module_description' => $pm?->description_override ?? $manifest->description,
                'is_core' => str_starts_with($modKey, 'core.'),
                'bundles' => $bundles,
            ];
        }

        return $result;
    }

    /**
     * ADR-169: mandatory field codes from catalog for a given jobdomain.
     */
    private static function resolveMandatoryFieldCodes(array $catalogFields, string $jobdomainKey): array
    {
        return collect($catalogFields)
            ->filter(fn ($f) => in_array($jobdomainKey, $f['validation_rules']['required_by_jobdomains'] ?? []))
            ->pluck('code')->values()->toArray();
    }

    /**
     * ADR-169: mandatory field codes per role.
     */
    private static function resolveMandatoryByRole(array $catalogFields, array $defaultRoles): array
    {
        $result = [];
        foreach ($defaultRoles as $roleKey => $roleDef) {
            $result[$roleKey] = collect($catalogFields)
                ->filter(fn ($f) => in_array($roleKey, $f['validation_rules']['required_by_roles'] ?? []))
                ->pluck('code')->values()->toArray();
        }

        return $result;
    }

    /**
     * ADR-178/179/182: Document presets — DB is source of truth.
     */
    private static function buildDocumentPresets(Jobdomain $jobdomain): array
    {
        $dbDocTypes = DocumentType::where('is_system', true)->whereNull('archived_at')->get();
        $defaultDocs = JobdomainGate::defaultDocumentsFor($jobdomain->key);
        $defaultDocCodes = collect($defaultDocs)->pluck('code')->toArray();
        $presetOrderMap = collect($defaultDocs)->pluck('order', 'code');

        return $dbDocTypes->map(function ($type) use ($defaultDocCodes, $presetOrderMap, $jobdomain) {
            $rules = $type->validation_rules ?? [];

            return [
                'code' => $type->code,
                'label' => $type->label,
                'scope' => $type->scope,
                'max_file_size_mb' => $rules['max_file_size_mb'] ?? 10,
                'accepted_types' => $rules['accepted_types'] ?? ['pdf', 'jpg', 'png'],
                'applicable_markets' => $rules['applicable_markets'] ?? null,
                'is_in_preset' => in_array($type->code, $defaultDocCodes),
                'mandatory_for_jobdomain' => in_array($jobdomain->key, $rules['required_by_jobdomains'] ?? []),
                'required_by_modules' => $rules['required_by_modules'] ?? [],
                'preset_order' => $presetOrderMap[$type->code] ?? null,
            ];
        })->values()->toArray();
    }
}
