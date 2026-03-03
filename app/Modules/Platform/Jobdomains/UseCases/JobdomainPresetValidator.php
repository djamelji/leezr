<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Validates jobdomain preset structures (fields, roles, documents).
 * Shared across Create and Update use cases.
 */
class JobdomainPresetValidator
{
    /**
     * Validate that default_fields entries reference existing field definitions
     * that are not platform_user scope.
     *
     * @param array<int, array{code: string, order?: int}> $fields
     */
    public static function validateDefaultFields(array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $codes = array_column($fields, 'code');
        $definitions = FieldDefinition::whereNull('company_id')
            ->whereIn('code', $codes)->get()->keyBy('code');

        $errors = [];
        foreach ($codes as $code) {
            $def = $definitions->get($code);

            if (!$def) {
                $errors[] = "Field '{$code}' does not exist.";
                continue;
            }

            if ($def->scope === FieldDefinition::SCOPE_PLATFORM_USER) {
                $errors[] = "Field '{$code}' is platform_user scope and cannot be a jobdomain preset.";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages(['default_fields' => $errors]);
        }
    }

    /**
     * Validate default_roles structure.
     * Expects associative array: key => {name, is_administrative?, bundles?, permissions?, fields?}
     */
    public static function validateDefaultRoles(array $roles): void
    {
        $validPermissionKeys = CompanyPermissionCatalog::keys();
        $validBundleKeys = ModuleRegistry::allBundleKeys();
        $errors = [];

        foreach ($roles as $roleKey => $roleDef) {
            if (!is_string($roleKey) || !preg_match('/^[a-z][a-z0-9_]*$/', $roleKey)) {
                $errors[] = "Invalid role key '{$roleKey}'.";
                continue;
            }

            if (empty($roleDef['name']) || !is_string($roleDef['name'])) {
                $errors[] = "Role '{$roleKey}' must have a name.";
            }

            foreach ($roleDef['bundles'] ?? [] as $bundleKey) {
                if (!in_array($bundleKey, $validBundleKeys, true)) {
                    $errors[] = "Role '{$roleKey}': unknown capability '{$bundleKey}'.";
                }
            }

            foreach ($roleDef['permissions'] ?? [] as $permKey) {
                if (!in_array($permKey, $validPermissionKeys, true)) {
                    $errors[] = "Role '{$roleKey}': unknown permission '{$permKey}'.";
                }
            }

            // ADR-164: Validate fields array if present
            foreach ($roleDef['fields'] ?? [] as $fieldEntry) {
                if (empty($fieldEntry['code']) || !is_string($fieldEntry['code'])) {
                    $errors[] = "Role '{$roleKey}': each field entry must have a 'code'.";
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages(['default_roles' => $errors]);
        }
    }

    /**
     * Validate that default_documents entries reference existing system document types.
     *
     * @param array<int, array{code: string, order?: int}> $documents
     */
    public static function validateDefaultDocuments(array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $codes = array_column($documents, 'code');
        $systemTypes = DocumentType::where('is_system', true)
            ->whereIn('code', $codes)->pluck('code')->toArray();

        $errors = [];
        foreach ($codes as $code) {
            if (!in_array($code, $systemTypes, true)) {
                $errors[] = "Document type '{$code}' does not exist or is not a system type.";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages(['default_documents' => $errors]);
        }
    }
}
