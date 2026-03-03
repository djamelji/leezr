<?php

namespace App\Core\Modules;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * Read model that builds the full module catalog for a company.
 * Combines platform_modules + company_modules + capabilities + entitlements into a single list.
 *
 * ADR-163: Adds display_state (from ModuleDisplayStateResolver), filters out
 * incompatible jobdomain modules and SYSTEM-state modules from the catalog.
 */
class ModuleCatalogReadModel
{
    /**
     * Get the full module catalog for a company.
     * Returns all visible, jobdomain-compatible modules with their display state.
     */
    public static function forCompany(Company $company): array
    {
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        $platformModules = PlatformModule::whereIn('key', $companyModuleKeys)
            ->orderBy('sort_order')
            ->get();

        $companyModules = CompanyModule::where('company_id', $company->id)
            ->get()
            ->keyBy('module_key');

        $activationReasons = CompanyModuleActivationReason::where('company_id', $company->id)
            ->get(['module_key', 'reason', 'source_module_key'])
            ->groupBy('module_key');

        $entitlements = EntitlementResolver::allForCompany($company);

        // ADR-167a: jobdomain is always present — structural invariant
        $jobdomain = $company->jobdomain;
        $companyPlan = CompanyEntitlements::planKey($company);

        return $platformModules
            ->sortBy(fn (PlatformModule $pm) => $pm->sort_order_override ?? $pm->sort_order)
            ->values()
            ->map(function (PlatformModule $pm) use ($companyModules, $activationReasons, $entitlements, $jobdomain, $companyPlan) {
                $cm = $companyModules->get($pm->key);
                $capabilities = ModuleRegistry::capabilities($pm->key);
                $manifest = ModuleRegistry::definitions()[$pm->key] ?? null;
                $entitlement = $entitlements[$pm->key] ?? ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];

                if (! $manifest) {
                    return null;
                }

                // ADR-163: Exclude modules incompatible with company's jobdomain
                // ADR-167a: jobdomain is always present — no null check
                if ($manifest->compatibleJobdomains !== null) {
                    if (! in_array($jobdomain->key, $manifest->compatibleJobdomains, true)) {
                        return null;
                    }
                }

                $isActive = $pm->is_enabled_globally
                    && ($manifest->type === 'core' || ($cm !== null && $cm->is_enabled_for_company));

                $type = $manifest->type;
                $minPlan = $pm->min_plan_override ?? $manifest->minPlan;
                $pricingMode = $pm->pricing_mode ?? 'included';

                // ADR-163: Compute display state
                $displayState = ModuleDisplayStateResolver::resolve(
                    $manifest, $pm, $entitlement, $isActive, $jobdomain, $companyPlan,
                );

                // ADR-163: SYSTEM modules are never exposed to the frontend
                if ($displayState === ModuleDisplayState::SYSTEM) {
                    return null;
                }

                return [
                    'key' => $pm->key,
                    'name' => $pm->display_name_override ?? $pm->name,
                    'description' => $pm->description_override ?? $pm->description,
                    'is_enabled_globally' => $pm->is_enabled_globally,
                    'is_enabled_for_company' => $cm?->is_enabled_for_company ?? false,
                    'is_active' => $isActive,
                    'capabilities' => $capabilities?->toArray() ?? [],
                    'type' => $type,
                    'category' => self::deriveCategory($type, $pricingMode, $minPlan, $manifest->compatibleJobdomains),
                    'settings_panels' => $capabilities?->settingsPanels ?? [],
                    'is_entitled' => $entitlement['entitled'],
                    'entitlement_source' => $entitlement['source'],
                    'entitlement_reason' => $entitlement['reason'],
                    'requires' => $manifest->requires,
                    'min_plan' => $minPlan,
                    'pricing_mode' => $pricingMode,
                    'icon_type' => $pm->icon_type ?? $manifest->iconType ?? 'tabler',
                    'icon_name' => $pm->icon_name ?? $manifest->iconRef ?? 'tabler-puzzle',
                    'activation_reasons' => ($activationReasons->get($pm->key) ?? collect())
                        ->map(fn ($r) => [
                            'reason' => $r->reason,
                            'source_module_key' => $r->source_module_key,
                        ])
                        ->values()
                        ->all(),

                    // ADR-163: Display state engine fields
                    'display_state' => $displayState->value,
                    'upgrade_target_plan' => $displayState === ModuleDisplayState::LOCKED_PLAN ? $minPlan : null,
                    'purchase_mode' => self::derivePurchaseMode($displayState),
                    'is_featured' => $manifest->marketplace['featured'] ?? false,
                    'is_included' => $displayState === ModuleDisplayState::INCLUDED,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function deriveCategory(string $type, string $pricingMode, ?string $minPlan, ?array $compatibleJobdomains): string
    {
        if ($type === 'core') {
            return 'core';
        }
        if ($pricingMode === 'addon' && $minPlan !== null) {
            return 'premium';
        }
        if ($compatibleJobdomains !== null) {
            return 'industry';
        }

        return 'addon';
    }

    private static function derivePurchaseMode(ModuleDisplayState $state): ?string
    {
        return match ($state) {
            ModuleDisplayState::LOCKED_PLAN => 'plan',
            ModuleDisplayState::LOCKED_ADDON => 'addon',
            ModuleDisplayState::CONTACT_SALES => 'sales',
            default => null,
        };
    }

    /**
     * Get only active modules for a company (for frontend consumption).
     */
    public static function activeForCompany(Company $company): array
    {
        return array_values(array_filter(
            static::forCompany($company),
            fn (array $module) => $module['is_active'],
        ));
    }
}
