<?php

namespace App\Modules\Platform\Modules\ReadModels;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;

class PlatformModuleReadModel
{
    /**
     * Dual-scope catalog: company modules (toggleable) + platform modules (read-only).
     */
    public static function catalog(): array
    {
        return [
            'company' => static::companyModules(),
            'platform' => static::platformModules(),
        ];
    }

    /**
     * Full detail for a single module.
     */
    public static function detail(string $key): ?array
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;

        if (! $manifest) {
            return null;
        }

        if ($manifest->scope === 'admin') {
            return static::adminDetail($key, $manifest);
        }

        if ($manifest->scope !== 'company') {
            return null;
        }

        return static::companyDetail($key, $manifest);
    }

    // ── Private projections ──────────────────────────

    private static function companyModules(): array
    {
        $companyDefinitions = ModuleRegistry::forScope('company');
        $companyModuleKeys = array_keys($companyDefinitions);

        return PlatformModule::whereIn('key', $companyModuleKeys)
            ->orderBy('sort_order')
            ->get()
            ->map(function (PlatformModule $module) use ($companyDefinitions) {
                $manifest = $companyDefinitions[$module->key] ?? null;
                $arr = $module->toArray();

                $arr['name'] = $module->display_name_override ?? $arr['name'];
                $arr['description'] = $module->description_override ?? $arr['description'];
                $arr['sort_order'] = $module->sort_order_override ?? $arr['sort_order'];
                $arr['min_plan'] = $module->min_plan_override ?? $manifest?->minPlan;

                $arr['type'] = $manifest?->type ?? 'addon';
                $arr['compatible_jobdomains'] = $manifest?->compatibleJobdomains;
                $arr['requires'] = $manifest?->requires ?? [];

                $arr['icon_type'] = $module->icon_type ?? $manifest?->iconType ?? 'tabler';
                $arr['icon_name'] = $module->icon_name ?? $manifest?->iconRef ?? 'tabler-puzzle';

                return $arr;
            })
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    private static function platformModules(): array
    {
        $platformDefinitions = ModuleRegistry::forScope('admin');
        $platformModuleKeys = array_keys($platformDefinitions);

        $platformModuleRows = PlatformModule::whereIn('key', $platformModuleKeys)
            ->get()
            ->keyBy('key');

        return collect($platformDefinitions)
            ->map(fn ($manifest) => [
                'key' => $manifest->key,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'visibility' => $manifest->visibility,
                'surface' => $manifest->surface,
                'sort_order' => $manifest->sortOrder,
                'icon_type' => $manifest->iconType,
                'icon_name' => $manifest->iconRef,
                'permissions' => array_map(fn ($p) => $p['key'] ?? $p, $manifest->permissions),
                'capabilities' => $manifest->capabilities->toArray(),
                'is_enabled_globally' => $platformModuleRows[$manifest->key]?->is_enabled_globally ?? true,
                'settings_route' => $manifest->settingsRoute,
            ])
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    private static function adminDetail(string $key, $manifest): array
    {
        $platformModule = PlatformModule::where('key', $key)->first();

        return [
            'module' => [
                'key' => $manifest->key,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'scope' => $manifest->scope,
                'surface' => $manifest->surface,
                'visibility' => $manifest->visibility,
                'sort_order' => $manifest->sortOrder,
                'is_enabled_globally' => $platformModule?->is_enabled_globally ?? true,
                'permissions' => $manifest->permissions,
                'bundles' => $manifest->bundles,
                'capabilities' => $manifest->capabilities->toArray(),
                'settings_route' => $manifest->settingsRoute,
                'icon_type' => $manifest->iconType,
                'icon_name' => $manifest->iconRef,
            ],
        ];
    }

    private static function companyDetail(string $key, $manifest): ?array
    {
        $platformModule = PlatformModule::where('key', $key)->first();

        if (! $platformModule) {
            return null;
        }

        // Reverse dependencies
        $dependents = [];
        foreach (ModuleRegistry::forScope('company') as $k => $m) {
            if (in_array($key, $m->requires, true)) {
                $dependents[] = ['key' => $k, 'name' => $m->name];
            }
        }

        // Companies actively using this module
        $companies = CompanyModule::where('module_key', $key)
            ->where('is_enabled_for_company', true)
            ->with('company:id,name,slug,status,plan_key')
            ->get()
            ->pluck('company')
            ->filter()
            ->values();

        // Compatible jobdomains (DB override ?? manifest)
        $resolvedCompatibleJobdomains = $platformModule->compatible_jobdomains_override ?? $manifest->compatibleJobdomains;
        $compatibleJobdomains = null;
        if ($resolvedCompatibleJobdomains !== null) {
            $compatibleJobdomains = Jobdomain::whereIn('key', $resolvedCompatibleJobdomains)
                ->select('id', 'key', 'label')
                ->get();
        }

        // All available jobdomains (for the override dropdown)
        $availableJobdomains = Jobdomain::select('id', 'key', 'label')
            ->orderBy('label')
            ->get();

        // Jobdomains that include this module by default
        $includedByJobdomains = Jobdomain::all()
            ->filter(fn ($jd) => in_array($key, $jd->default_modules ?? [], true))
            ->map(fn ($jd) => ['id' => $jd->id, 'key' => $jd->key, 'label' => $jd->label])
            ->values();

        return [
            'module' => [
                'key' => $manifest->key,
                'name' => $platformModule->display_name_override ?? $manifest->name,
                'description' => $platformModule->description_override ?? $manifest->description,
                'min_plan' => $platformModule->min_plan_override ?? $manifest->minPlan,
                'sort_order' => $platformModule->sort_order_override ?? $manifest->sortOrder,
                'type' => $manifest->type,
                'scope' => $manifest->scope,
                'surface' => $manifest->surface,
                'compatible_jobdomains' => $resolvedCompatibleJobdomains,
                'requires' => $manifest->requires,
                'is_enabled_globally' => $platformModule->is_enabled_globally,
                'permissions_count' => count($manifest->permissions),
                'bundles_count' => count($manifest->bundles),
                'permissions' => $manifest->permissions,
                'bundles' => $manifest->bundles,
                'icon_type' => $platformModule->icon_type ?? $manifest->iconType,
                'icon_name' => $platformModule->icon_name ?? $manifest->iconRef,
            ],
            'manifest_defaults' => [
                'name' => $manifest->name,
                'description' => $manifest->description,
                'min_plan' => $manifest->minPlan,
                'sort_order' => $manifest->sortOrder,
                'compatible_jobdomains' => $manifest->compatibleJobdomains,
            ],
            'platform_config' => $platformModule->only([
                'is_listed',
                'is_sellable',
                'addon_pricing',
                'settings_schema',
                'notes',
                'display_name_override',
                'description_override',
                'min_plan_override',
                'sort_order_override',
                'compatible_jobdomains_override',
                'icon_type',
                'icon_name',
            ]),
            'dependents' => $dependents,
            'companies' => $companies,
            'compatible_jobdomains_detail' => $compatibleJobdomains,
            'included_by_jobdomains' => $includedByJobdomains,
            'available_jobdomains' => $availableJobdomains,
        ];
    }
}
