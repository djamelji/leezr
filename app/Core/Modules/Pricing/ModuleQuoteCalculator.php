<?php

namespace App\Core\Modules\Pricing;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Models\Company;
use App\Core\Modules\DependencyGraph;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleGate;
use App\Core\Billing\WalletLedger;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use InvalidArgumentException;

/**
 * Deterministic module pricing quote calculator.
 *
 * Pipeline:
 *   1. Validate selected keys exist, are company-scope, and globally enabled
 *   2. Check entitlement for each selected module
 *   3. Expand selection via requiresClosure()
 *   4. Only selected modules are billable (addon-priced)
 *   5. Required modules are included (not billed)
 *   6. Compute amounts using flat/plan_flat pricing only
 *   7. Sort lines alphabetically by key for determinism
 *
 * Deterministic: same input → same output.
 */
class ModuleQuoteCalculator
{
    /**
     * Generate a quote for the given company and module selection.
     *
     * @param Company $company The company requesting the quote
     * @param string[] $selectedModuleKeys Module keys explicitly selected by the user
     * @return Quote
     *
     * @throws InvalidArgumentException If a module key is invalid or not available
     */
    public static function quoteForCompany(Company $company, array $selectedModuleKeys): Quote
    {
        $currency = WalletLedger::ensureWallet($company)->currency;

        if (empty($selectedModuleKeys)) {
            return new Quote(
                total: 0,
                currency: $currency,
                lines: [],
                included: [],
            );
        }

        // Deduplicate and sort for determinism
        $selectedModuleKeys = array_unique($selectedModuleKeys);
        sort($selectedModuleKeys);

        $definitions = ModuleRegistry::definitions();
        $companyPlanKey = CompanyEntitlements::planKey($company);

        // 1. Validate all selected keys
        foreach ($selectedModuleKeys as $key) {
            $manifest = $definitions[$key] ?? null;

            if (!$manifest || $manifest->scope !== 'company') {
                throw new InvalidArgumentException(
                    "Module '{$key}' does not exist or is not a company-scope module."
                );
            }

            if (!ModuleGate::isEnabledGlobally($key)) {
                throw new InvalidArgumentException(
                    "Module '{$key}' is not available globally."
                );
            }

            // 2. Check entitlement
            $entitlement = EntitlementResolver::check($company, $key);

            if (!$entitlement['entitled']) {
                throw new InvalidArgumentException(
                    "Module '{$key}' is not available for this company: {$entitlement['reason']}."
                );
            }
        }

        // 3. Expand via requiresClosure
        $allRequiredKeys = [];

        foreach ($selectedModuleKeys as $key) {
            $closure = DependencyGraph::requiresClosure($key);

            foreach ($closure as $reqKey) {
                if (!in_array($reqKey, $selectedModuleKeys, true)) {
                    $allRequiredKeys[$reqKey] = true;
                }
            }
        }

        $requiredKeys = array_keys($allRequiredKeys);
        sort($requiredKeys);

        // 4. Build billable lines (selected modules with addon pricing only)
        $lines = [];
        $total = 0;

        foreach ($selectedModuleKeys as $key) {
            $pm = PlatformModule::where('key', $key)->first();

            if (!$pm || $pm->addon_pricing === null) {
                // No addon pricing → included in plan, no charge
                continue;
            }

            $amount = static::computeAmount($pm, $companyPlanKey);
            $manifest = $definitions[$key];

            $lines[] = new QuoteLine(
                key: $key,
                title: $pm->display_name_override ?? $manifest->name,
                amount: $amount,
                pricingModel: $pm->addon_pricing['pricing_model'] ?? 'flat',
            );

            $total += $amount;
        }

        // 5. Build included list (required modules not in selection)
        $included = [];

        foreach ($requiredKeys as $reqKey) {
            $manifest = $definitions[$reqKey] ?? null;

            if (!$manifest) {
                continue;
            }

            $pm = PlatformModule::where('key', $reqKey)->first();

            $included[] = new QuoteIncluded(
                key: $reqKey,
                title: $pm?->display_name_override ?? $manifest->name,
            );
        }

        return new Quote(
            total: $total,
            currency: $currency,
            lines: $lines,
            included: $included,
        );
    }

    /**
     * Compute the amount in cents for a module based on its pricing model.
     *
     * Supports: flat, plan_flat.
     * Other models return 0 (future: per_seat, usage, tiered).
     */
    public static function computeAmount(PlatformModule $pm, string $companyPlanKey): int
    {
        $addon = $pm->addon_pricing ?? [];
        $params = $addon['pricing_params'] ?? [];

        return match ($addon['pricing_model'] ?? 'flat') {
            'flat' => (int) round(($params['price_monthly'] ?? 0) * 100),
            'plan_flat' => (int) round(($params[$companyPlanKey] ?? 0) * 100),
            default => 0,
        };
    }
}
