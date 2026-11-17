<?php

namespace App\Core\Modules;

use App\Core\Billing\CompanyAddonSubscription;
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
        $setupSvc = new \App\Core\Modules\Setup\ModuleSetupService($company); // ADR-803

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

        // ADR-621 (LOT 6d): the PAID addon subscriptions actually contracted (distinct from technical
        // activation and from contractual entitlement). Carries the FROZEN snapshot price (ADR-220).
        $addonSubs = CompanyAddonSubscription::where('company_id', $company->id)->active()->get()->keyBy('module_key');

        // ADR-167a: jobdomain is always present — structural invariant
        $jobdomain = $company->jobdomain;
        $companyPlan = CompanyEntitlements::planKey($company);

        // ADR-208: Compute reverse dependency map (in-memory, no DB query)
        $dependentsMap = [];

        foreach (ModuleRegistry::forScope('company') as $k => $m) {
            foreach ($m->requires as $req) {
                $dependentsMap[$req][] = $k;
            }
        }

        return $platformModules
            ->sortBy(fn (PlatformModule $pm) => $pm->sort_order_override ?? $pm->sort_order)
            ->values()
            ->map(function (PlatformModule $pm) use ($company, $companyModules, $activationReasons, $entitlements, $jobdomain, $companyPlan, $dependentsMap, $addonSubs, $setupSvc) {
                $cm = $companyModules->get($pm->key);
                $capabilities = ModuleRegistry::capabilities($pm->key);
                $manifest = ModuleRegistry::definitions()[$pm->key] ?? null;
                $entitlement = $entitlements[$pm->key] ?? ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];

                if (! $manifest) {
                    return null;
                }

                // Logistics Only (ADR-818) — un module non livré (« en préparation ») n'est plus exposé au
                // catalogue d'une société qui ne l'a pas : aucun « bientôt disponible ». Une société qui l'a déjà
                // (activation ou abonnement historique) le garde visible : rien n'est désactivé rétroactivement.
                if (! in_array(ModuleCommercialPolicy::statusFor($pm->key), ModuleCommercialPolicy::SELLABLE_STATUSES, true)
                    && ! ($cm?->is_enabled_for_company) && ! $addonSubs->has($pm->key)) {
                    return null;
                }

                // ADR-163/204: Exclude modules incompatible with company's jobdomain
                // ADR-167a: jobdomain is always present — no null check
                // ADR-203: DB override takes priority over manifest
                $compatibleJobdomains = $pm->compatible_jobdomains_override ?? $manifest->compatibleJobdomains;
                if ($compatibleJobdomains !== null) {
                    if (! in_array($jobdomain->key, $compatibleJobdomains, true)) {
                        return null;
                    }
                }

                // ADR-209: Use ModuleGate (checks entitlement + activation reason)
                // instead of raw DB flag which can be stale
                $isActive = ModuleGate::isActive($company, $pm->key);

                $type = $manifest->type;
                $minPlan = $pm->min_plan_override ?? $manifest->minPlan;

                // ADR-163: Compute display state
                $displayState = ModuleDisplayStateResolver::resolve(
                    $manifest, $pm, $entitlement, $isActive, $jobdomain, $companyPlan,
                );

                // ADR-163: SYSTEM modules are never exposed to the frontend
                if ($displayState === ModuleDisplayState::SYSTEM) {
                    return null;
                }

                // ADR-732 (P0-A) — autorité commerciale unique : un module non commercialisable n'expose
                // AUCUN prix commandable (addon_pricing/purchase_mode neutralisés) et porte son statut
                // (« preview » → « En préparation »). Les abonnements déjà contractés restent visibles
                // (is_subscribed) — aucune révocation.
                $commercialStatus = ModuleCommercialPolicy::statusFor($pm->key);
                $purchasable = ModuleCommercialPolicy::isPurchasable($company, $pm->key);
                // Neutralisation du prix commandable UNIQUEMENT pour les statuts non vendables (preview/
                // internal/demo/deprecated). Un module 'available' non self-service (contact_sales) garde
                // son CTA et son mode d'achat — seul le prix « acheter maintenant » est retiré aux non-vendables.
                $sellableStatus = in_array($commercialStatus, ModuleCommercialPolicy::SELLABLE_STATUSES, true);

                return [
                    'key' => $pm->key,
                    'name' => $pm->display_name_override ?? $pm->name,
                    'description' => $pm->description_override ?? $pm->description,
                    'is_enabled_globally' => $pm->is_enabled_globally,
                    'is_enabled_for_company' => $cm?->is_enabled_for_company ?? false,
                    'is_active' => $isActive,
                    'capabilities' => $capabilities?->toArray() ?? [],
                    'type' => $type,
                    'category' => self::deriveCategory($type, $pm->addon_pricing !== null, $minPlan, $manifest->compatibleJobdomains),
                    'settings_panels' => $capabilities?->settingsPanels ?? [],
                    'is_entitled' => $entitlement['entitled'],
                    'entitlement_source' => $entitlement['source'],
                    'entitlement_reason' => $entitlement['reason'],
                    'requires' => $manifest->requires,
                    // ADR-820 : proposé par le profil métier (jamais activé ni acheté pour autant)
                    'recommended_for_jobdomain' => in_array($pm->key, (array) ($jobdomain->recommended_modules ?? []), true),
                    // Logistics Only : « requis par » ne nomme jamais un module non livré absent de la société.
                    'dependents' => array_values(array_filter($dependentsMap[$pm->key] ?? [], fn ($d) => in_array(ModuleCommercialPolicy::statusFor($d), ModuleCommercialPolicy::SELLABLE_STATUSES, true)
                        || ($companyModules->get($d)?->is_enabled_for_company))),
                    'min_plan' => $minPlan,
                    'commercial_status' => $commercialStatus,
                    'purchasable' => $purchasable,
                    'pricing_mode' => $sellableStatus ? $pm->effectivePricingModeFor($company) : 'unavailable',
                    'addon_pricing' => $sellableStatus ? $pm->addon_pricing : null,
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
                    'purchase_mode' => $sellableStatus ? self::derivePurchaseMode($displayState) : null,
                    'is_featured' => $manifest->marketplace['featured'] ?? false,
                    'is_included' => $displayState === ModuleDisplayState::INCLUDED,

                    // ADR-621 (LOT 6d): paid-subscription truth, distinct from activation/entitlement.
                    // is_subscribed = a real paid CompanyAddonSubscription exists; contracted_amount_cents
                    // is its FROZEN snapshot price (HT) — NOT the current catalog price.
                    'is_subscribed' => $addonSubs->has($pm->key),
                    'contracted_amount_cents' => $addonSubs->get($pm->key)?->amount_cents,
                    'subscribed_interval' => $addonSubs->get($pm->key)?->interval,

                    // ADR-803 — guided-configuration state (null = module without setup contract, historical path).
                    'setup_status' => $setupSvc->status($pm->key),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function deriveCategory(string $type, bool $hasAddonPricing, ?string $minPlan, ?array $compatibleJobdomains): string
    {
        if ($type === 'core') {
            return 'core';
        }
        if ($hasAddonPricing && $minPlan !== null) {
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
