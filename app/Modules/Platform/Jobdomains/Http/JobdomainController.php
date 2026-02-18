<?php

namespace App\Modules\Platform\Jobdomains\Http;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JobdomainController extends Controller
{
    public function index(): JsonResponse
    {
        $jobdomains = Jobdomain::withCount('companies')->get();

        return response()->json([
            'jobdomains' => $jobdomains,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $jobdomain = Jobdomain::withCount('companies')->findOrFail($id);

        $fieldDefinitions = FieldDefinition::whereNull('company_id')
            ->whereIn('scope', [
                FieldDefinition::SCOPE_COMPANY,
                FieldDefinition::SCOPE_COMPANY_USER,
            ])->orderBy('default_order')->get();

        // Build module bundles for the platform role template UI (company-scope only)
        $modules = collect(ModuleRegistry::forScope('company'));
        $moduleBundles = [];

        foreach ($modules as $modKey => $manifest) {
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

            $moduleBundles[] = [
                'module_key' => $modKey,
                'module_name' => $manifest->name,
                'module_description' => $manifest->description,
                'is_core' => str_starts_with($modKey, 'core.'),
                'bundles' => $bundles,
            ];
        }

        return response()->json([
            'jobdomain' => $jobdomain,
            'field_definitions' => $fieldDefinitions,
            'permission_catalog' => CompanyPermissionCatalog::all(),
            'module_bundles' => $moduleBundles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:jobdomains,key'],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'default_modules' => ['sometimes', 'array'],
            'default_modules.*' => ['string'],
            'default_fields' => ['sometimes', 'array'],
            'default_fields.*.code' => ['required', 'string'],
            'default_fields.*.required' => ['sometimes', 'boolean'],
            'default_fields.*.order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (!empty($validated['default_fields'])) {
            $this->validateDefaultFields($validated['default_fields']);
        }

        $jobdomain = Jobdomain::create([
            'key' => $validated['key'],
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
            'default_modules' => $validated['default_modules'] ?? [],
            'default_fields' => $validated['default_fields'] ?? [],
        ]);

        $jobdomain->loadCount('companies');

        return response()->json([
            'message' => 'Job domain created.',
            'jobdomain' => $jobdomain,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $jobdomain = Jobdomain::findOrFail($id);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_custom_fields' => ['sometimes', 'boolean'],
            'default_modules' => ['sometimes', 'array'],
            'default_modules.*' => ['string'],
            'default_fields' => ['sometimes', 'array'],
            'default_fields.*.code' => ['required', 'string'],
            'default_fields.*.required' => ['sometimes', 'boolean'],
            'default_fields.*.order' => ['sometimes', 'integer', 'min:0'],
            'default_roles' => ['sometimes', 'array'],
        ]);

        if (isset($validated['default_fields'])) {
            $this->validateDefaultFields($validated['default_fields']);
        }

        if (isset($validated['default_roles'])) {
            $this->validateDefaultRoles($validated['default_roles']);
        }

        $jobdomain->update($validated);
        $jobdomain->loadCount('companies');

        return response()->json([
            'message' => 'Job domain updated.',
            'jobdomain' => $jobdomain,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $jobdomain = Jobdomain::withCount('companies')->findOrFail($id);

        if ($jobdomain->companies_count > 0) {
            return response()->json([
                'message' => "Cannot delete: this job domain is assigned to {$jobdomain->companies_count} company(ies).",
            ], 422);
        }

        $jobdomain->delete();

        return response()->json([
            'message' => 'Job domain deleted.',
        ]);
    }

    /**
     * Validate default_roles structure.
     * Expects associative array: key => {name, is_administrative?, bundles?, permissions?}
     */
    private function validateDefaultRoles(array $roles): void
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
        }

        if (!empty($errors)) {
            abort(422, implode(' ', $errors));
        }
    }

    /**
     * Validate that default_fields entries reference existing field definitions
     * that are not platform_user scope.
     *
     * @param array<int, array{code: string, required?: bool, order?: int}> $fields
     */
    private function validateDefaultFields(array $fields): void
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
            abort(422, implode(' ', $errors));
        }
    }
}
