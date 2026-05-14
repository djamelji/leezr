<?php

namespace App\Core\Policies;

use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use Carbon\Carbon;

class CompanyPolicyResolver
{
    /**
     * Resolve a single policy value for a company.
     * Falls back to MarketRuleSet if no company policy exists.
     */
    public function get(int $companyId, string $domain, string $policyKey, ?Carbon $at = null): mixed
    {
        $at ??= now();

        $policy = CompanyPolicy::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->domain($domain)
            ->forKey($policyKey)
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();

        if ($policy) {
            return $policy->value;
        }

        // Fallback to MarketRuleSet
        $company = Company::find($companyId);

        if (! $company || ! $company->market_key) {
            return null;
        }

        $rule = MarketRuleSet::where('market_key', $company->market_key)
            ->domain($domain)
            ->forRule($policyKey)
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();

        return $rule?->value;
    }

    /**
     * Snapshot ALL active policies for a domain at a given date.
     * Used by PayrollPeriod.rule_snapshot and Timesheet.metadata.
     */
    public function snapshot(int $companyId, string $domain, ?Carbon $at = null): array
    {
        $at ??= now();

        $policies = CompanyPolicy::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->domain($domain)
            ->activeAt($at)
            ->get()
            ->mapWithKeys(fn (CompanyPolicy $p) => [$p->policy_key => $p->value])
            ->toArray();

        // Merge with MarketRuleSet defaults (company policies take precedence)
        $company = Company::find($companyId);

        if ($company && $company->market_key) {
            $marketRules = MarketRuleSet::where('market_key', $company->market_key)
                ->domain($domain)
                ->activeAt($at)
                ->get()
                ->mapWithKeys(fn (MarketRuleSet $r) => [$r->rule_key => $r->value])
                ->toArray();

            // Market rules as base, company policies override
            $policies = array_merge($marketRules, $policies);
        }

        return [
            'company_id' => $companyId,
            'domain' => $domain,
            'snapshot_at' => $at->toIso8601String(),
            'policies' => $policies,
        ];
    }

    /**
     * Check if a specific policy exists for this company (not fallback).
     */
    public function has(int $companyId, string $domain, string $policyKey, ?Carbon $at = null): bool
    {
        $at ??= now();

        return CompanyPolicy::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->domain($domain)
            ->forKey($policyKey)
            ->activeAt($at)
            ->exists();
    }

    /**
     * Set a policy value for a company. Creates a new versioned entry.
     */
    public function set(
        int $companyId,
        string $domain,
        string $policyKey,
        mixed $value,
        string $source = 'admin_manual',
        ?Carbon $effectiveFrom = null,
    ): CompanyPolicy {
        $effectiveFrom ??= now();

        // Close previous active policy
        CompanyPolicy::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->domain($domain)
            ->forKey($policyKey)
            ->whereNull('effective_until')
            ->update(['effective_until' => $effectiveFrom->subDay()]);

        return CompanyPolicy::create([
            'company_id' => $companyId,
            'domain' => $domain,
            'policy_key' => $policyKey,
            'value' => $value,
            'effective_from' => $effectiveFrom,
            'effective_until' => null,
            'source' => $source,
        ]);
    }
}
