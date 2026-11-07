<?php

namespace App\Core\Modules;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * Computes module entitlements dynamically — no entitlement table.
 *
 * Resolution order:
 *   1. Core gate — type 'core' always entitled, cannot be disabled
 *   2. Plan gate — company plan must meet module's min_plan
 *   3. Compat gate — company jobdomain must be in compatible_jobdomains
 *   4. Source gate — module must be available via jobdomain (or future addon purchase)
 */
class EntitlementResolver
{
    /**
     * Check if a company is entitled to a module.
     *
     * @return array{entitled: bool, source: ?string, reason: ?string}
     *   source: 'core' | 'jobdomain' | 'jobdomain_dependency' | 'addon' | null
     *   reason: 'unknown_module' | 'plan_required' | 'incompatible_jobdomain'
     *           | 'addon_inactive' | 'not_available' | null
     */
    public static function check(Company $company, string $moduleKey, ?PlatformModule $platformModule = null): array
    {
        $manifest = ModuleRegistry::definitions()[$moduleKey] ?? null;

        if (!$manifest || $manifest->scope !== 'company') {
            return ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];
        }

        // Gate 1: Core modules are always entitled
        if ($manifest->type === 'core') {
            return ['entitled' => true, 'source' => 'core', 'reason' => null];
        }

        // Gate 2: Plan check
        if ($manifest->minPlan !== null) {
            if (!PlanRegistry::meetsRequirement(CompanyEntitlements::planKey($company), $manifest->minPlan)) {
                return ['entitled' => false, 'source' => null, 'reason' => 'plan_required'];
            }
        }

        // Gate 3: Jobdomain compatibility (DB override ?? manifest)
        // ADR-167a: jobdomain_key is always present — no null check
        $pm = $platformModule ?? PlatformModule::where('key', $moduleKey)->first();
        $compatibleJobdomains = $pm?->compatible_jobdomains_override ?? $manifest->compatibleJobdomains;

        if ($compatibleJobdomains !== null) {
            if (!in_array($company->jobdomain_key, $compatibleJobdomains, true)) {
                return ['entitled' => false, 'source' => null, 'reason' => 'incompatible_jobdomain'];
            }
        }

        // Gate 4: Source — is the module available via jobdomain?
        $defaultModules = $company->jobdomain->default_modules ?? [];

        if (in_array($moduleKey, $defaultModules, true)) {
            return ['entitled' => true, 'source' => 'jobdomain', 'reason' => null];
        }

        // Gate 4b (ADR-211): Module is a dependency of a jobdomain default
        foreach ($defaultModules as $defaultKey) {
            $requires = ModuleActivationEngine::collectTransitiveRequires($defaultKey);
            if (in_array($moduleKey, $requires, true)) {
                return ['entitled' => true, 'source' => 'jobdomain_dependency', 'reason' => null];
            }
        }

        // Gate 5 (ADR-212 + P0-C/ADR-734): Module with addon_pricing is *purchasable*.
        // addon_pricing alone no longer grants the paid feature — the effective right
        // depends on a valid commercial state (an active CompanyAddonSubscription).
        //
        // Conservative coupling, to avoid cutting legitimate rights:
        //   - no commercial record at all → PRESERVED (admin grant / legacy / free exception)
        //   - at least one ACTIVE addon subscription → entitled
        //   - only DEACTIVATED subscription(s) remain → cut (reason 'addon_inactive')
        //
        // Deactivation is driven by the dunning escalation (DunningTransitioner), so a
        // first payment incident under grace does NOT cut the addon — only final suspension does.
        if ($pm && $pm->addon_pricing !== null) {
            $base = CompanyAddonSubscription::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('module_key', $moduleKey);

            if (!$base->clone()->exists()) {
                return ['entitled' => true, 'source' => 'addon', 'reason' => null];
            }

            $hasActive = $base->clone()->active()->exists();

            return $hasActive
                ? ['entitled' => true, 'source' => 'addon', 'reason' => null]
                : ['entitled' => false, 'source' => null, 'reason' => 'addon_inactive'];
        }

        return ['entitled' => false, 'source' => null, 'reason' => 'not_available'];
    }

    /**
     * Entitlement map for all company-scope modules.
     *
     * @return array<string, array{entitled: bool, source: ?string, reason: ?string}>
     */
    public static function allForCompany(Company $company): array
    {
        $result = [];

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            $result[$key] = static::check($company, $key);
        }

        return $result;
    }
}
