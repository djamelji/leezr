<?php

namespace App\Core\Workforce\Services;

use App\Core\Markets\MarketRuleSet;
use App\Core\Workforce\CompanyPayrollRuleOverride;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\EmployeeTaxProfile;
use Carbon\Carbon;

class PayrollRuleResolver
{
    /**
     * Resolve contribution rules from MarketRuleSet for a given market and date.
     * Returns array of rules, each with: code, label, category, base_type, employee_rate_bps, employer_rate_bps
     */
    public function resolveContributions(string $marketKey, Carbon $at): array
    {
        $rules = MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'like', 'contribution_%')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderBy('rule_key')
            ->get();

        return $rules->map(fn (MarketRuleSet $rule) => array_merge(
            $rule->value,
            ['rule_key' => $rule->rule_key, 'source' => $rule->source, 'reference' => $rule->reference]
        ))->toArray();
    }

    /**
     * Resolve plafond SS monthly from MarketRuleSet.
     */
    public function resolvePlafondSS(string $marketKey, Carbon $at): int
    {
        $rule = MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'plafond_ss_monthly_cents')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderByDesc('effective_from')
            ->first();

        return (int) ($rule?->value['amount'] ?? 0);
    }

    /**
     * Resolve CSG base multiplier from MarketRuleSet (e.g. 9825 = 98.25%).
     */
    public function resolveCsgBaseMultiplier(string $marketKey, Carbon $at): int
    {
        $rule = MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'csg_base_multiplier')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderByDesc('effective_from')
            ->first();

        return (int) ($rule?->value['multiplier_bps'] ?? 9825);
    }

    /**
     * Resolve PAS tax rate for an employee.
     * Returns rate in bps (basis points). Default = 0 if no profile found.
     */
    public function resolveTaxRate(int $companyId, int $employeeId, Carbon $at): int
    {
        $profile = EmployeeTaxProfile::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderByDesc('effective_from')
            ->first();

        return $profile?->tax_rate_bps ?? 0;
    }

    /**
     * Full snapshot of all rules used — for audit trail.
     */
    public function snapshot(string $marketKey, Carbon $at): array
    {
        $contributions = $this->resolveContributions($marketKey, $at);
        $plafondSS = $this->resolvePlafondSS($marketKey, $at);
        $csgMultiplier = $this->resolveCsgBaseMultiplier($marketKey, $at);

        return [
            'market_key' => $marketKey,
            'resolved_at' => $at->toIso8601String(),
            'plafond_ss_monthly_cents' => $plafondSS,
            'csg_base_multiplier_bps' => $csgMultiplier,
            'contribution_rules_count' => count($contributions),
            'contribution_rules' => $contributions,
        ];
    }

    // ── Sprint 6.1: RGDU meta-rules ──

    /**
     * Resolve SMIC horaire brut from MarketRuleSet.
     * Returns amount in cents (e.g. 1147 = 11.47 EUR).
     * Returns null if no rule found (→ blocking anomaly in engine).
     */
    public function resolveSmicHoraire(string $marketKey, Carbon $at): ?int
    {
        $rule = MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'smic_horaire_cents')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderByDesc('effective_from')
            ->first();

        return $rule ? (int) $rule->value['amount'] : null;
    }

    /**
     * Resolve RGDU coefficient T from MarketRuleSet.
     * Returns bps value based on company headcount.
     * Returns null if no rule found (→ blocking anomaly in engine).
     */
    public function resolveRgduCoefficientT(string $marketKey, Carbon $at, ?int $averageHeadcount): ?int
    {
        $rule = MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'rgdu_coefficient_t')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderByDesc('effective_from')
            ->first();

        if (! $rule) {
            return null;
        }

        $headcount = $averageHeadcount ?? 0;

        return $headcount >= 50
            ? (int) $rule->value['fifty_or_more_bps']
            : (int) $rule->value['less_than_50_bps'];
    }

    /**
     * Resolve RGDU eligible contribution codes from MarketRuleSet.
     * Returns array of contribution code strings.
     */
    public function resolveRgduEligibleCodes(string $marketKey, Carbon $at): array
    {
        $rule = MarketRuleSet::where('market_key', $marketKey)
            ->where('domain', 'workforce_payroll')
            ->where('rule_key', 'rgdu_eligible_codes')
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at->format('Y-m-d')))
            ->orderByDesc('effective_from')
            ->first();

        return $rule ? ($rule->value['codes'] ?? []) : [];
    }

    // ── Phase 4.1: CC + Company Override resolution (ADR-501) ──

    /**
     * Resolve contributions with full 3-level chain: Market → CC → Company Override.
     * Returns enriched rules with source_level, source_ref, resolution_chain.
     */
    public function resolveContributionsWithCC(
        string $marketKey,
        Carbon $at,
        ?int $conventionCollectiveId = null,
        ?int $companyId = null,
    ): array {
        // Level 1: Market rules (base légale)
        $marketRules = $this->resolveContributions($marketKey, $at);
        $resolved = [];

        foreach ($marketRules as $rule) {
            $resolved[$rule['rule_key']] = $this->enrichRule($rule, 'market_law', $rule['source'] ?? $rule['reference'] ?? 'market', null, ['market_law']);
        }

        // Level 2: Convention Collective rules
        $ccRulesRaw = [];
        if ($conventionCollectiveId !== null) {
            $ccRulesRaw = ConventionCollectiveRule::where('convention_collective_id', $conventionCollectiveId)
                ->domain('workforce_payroll')
                ->whereIn('rule_type', ['contribution', 'benefit'])
                ->activeAt($at)
                ->orderBy('rule_key')
                ->get()
                ->toArray();
        }

        foreach ($ccRulesRaw as $ccRule) {
            $ruleKey = $ccRule['rule_key'];
            $overrideMode = $ccRule['override_mode'] ?? 'replace';
            $ccEnriched = $this->enrichRule(
                array_merge($ccRule['value'], ['rule_key' => $ruleKey]),
                'convention_collective',
                $ccRule['source'],
                $overrideMode,
                ['market_law', 'convention_collective'],
                $ccRule['rule_type'],
                $ccRule['effective_from'],
                $ccRule['effective_until'] ?? null,
            );

            $resolved = $this->applyOverride($resolved, $ruleKey, $ccEnriched, $overrideMode);
        }

        // Level 3: Company overrides
        $companyRulesRaw = [];
        if ($companyId !== null) {
            $companyRulesRaw = CompanyPayrollRuleOverride::where('company_id', $companyId)
                ->domain('workforce_payroll')
                ->whereIn('rule_type', ['contribution', 'benefit'])
                ->activeAt($at)
                ->orderBy('rule_key')
                ->get()
                ->toArray();
        }

        foreach ($companyRulesRaw as $coRule) {
            $ruleKey = $coRule['rule_key'];
            $overrideMode = $coRule['override_mode'] ?? 'replace';
            $coEnriched = $this->enrichRule(
                array_merge($coRule['value'], ['rule_key' => $ruleKey]),
                'company_override',
                $coRule['source'],
                $overrideMode,
                ['market_law', 'convention_collective', 'company_override'],
                $coRule['rule_type'],
                $coRule['effective_from'],
                $coRule['effective_until'] ?? null,
            );

            $resolved = $this->applyOverride($resolved, $ruleKey, $coEnriched, $overrideMode);
        }

        return array_values($resolved);
    }

    /**
     * Full snapshot with CC + Company Override context — for audit trail.
     * Contains all data needed to explain a calculation without DB access.
     */
    public function snapshotWithCC(
        string $marketKey,
        Carbon $at,
        ?int $conventionCollectiveId = null,
        ?int $companyId = null,
    ): array {
        $rules = $this->resolveContributionsWithCC($marketKey, $at, $conventionCollectiveId, $companyId);
        $plafondSS = $this->resolvePlafondSS($marketKey, $at);
        $csgMultiplier = $this->resolveCsgBaseMultiplier($marketKey, $at);

        // CC context
        $ccContext = null;
        if ($conventionCollectiveId !== null) {
            $cc = ConventionCollective::find($conventionCollectiveId);
            if ($cc) {
                $ccRulesApplied = ConventionCollectiveRule::where('convention_collective_id', $conventionCollectiveId)
                    ->domain('workforce_payroll')
                    ->whereIn('rule_type', ['contribution', 'benefit'])
                    ->activeAt($at)
                    ->get();

                $modesUsed = $ccRulesApplied->pluck('override_mode')->unique()->values()->toArray();

                $ccContext = [
                    'id' => $cc->id,
                    'idcc' => $cc->idcc,
                    'name' => $cc->short_name,
                    'brochure_number' => $cc->brochure_number,
                    'rules_applied' => $ccRulesApplied->map(fn ($r) => [
                        'rule_key' => $r->rule_key,
                        'rule_type' => $r->rule_type,
                        'override_mode' => $r->override_mode,
                        'effective_from' => $r->effective_from->format('Y-m-d'),
                        'effective_until' => $r->effective_until?->format('Y-m-d'),
                        'source' => $r->source,
                        'value' => $r->value,
                    ])->toArray(),
                    'rules_applied_count' => $ccRulesApplied->count(),
                    'override_modes_used' => $modesUsed,
                ];
            }
        }

        // Company override context
        $companyContext = null;
        if ($companyId !== null) {
            $companyOverrides = CompanyPayrollRuleOverride::where('company_id', $companyId)
                ->domain('workforce_payroll')
                ->whereIn('rule_type', ['contribution', 'benefit'])
                ->activeAt($at)
                ->get();

            if ($companyOverrides->isNotEmpty()) {
                $companyContext = [
                    'company_id' => $companyId,
                    'rules_applied' => $companyOverrides->map(fn ($r) => [
                        'rule_key' => $r->rule_key,
                        'rule_type' => $r->rule_type,
                        'override_mode' => $r->override_mode,
                        'effective_from' => $r->effective_from->format('Y-m-d'),
                        'effective_until' => $r->effective_until?->format('Y-m-d'),
                        'source' => $r->source,
                        'approved_by' => $r->approved_by,
                        'justification' => $r->justification,
                        'value' => $r->value,
                    ])->toArray(),
                    'rules_applied_count' => $companyOverrides->count(),
                ];
            }
        }

        return [
            'market_key' => $marketKey,
            'resolved_at' => $at->toIso8601String(),
            'resolver_version' => 'payroll-resolver-v2',
            'plafond_ss_monthly_cents' => $plafondSS,
            'csg_base_multiplier_bps' => $csgMultiplier,
            'convention_collective' => $ccContext,
            'company_override' => $companyContext,
            'contribution_rules_count' => count($rules),
            'contribution_rules' => $rules,
        ];
    }

    // ── Private helpers ──

    /**
     * Enrich a rule array with resolution metadata.
     */
    private function enrichRule(
        array $rule,
        string $sourceLevel,
        string $sourceRef,
        ?string $overrideMode,
        array $resolutionChain,
        ?string $ruleType = null,
        ?string $effectiveFrom = null,
        ?string $effectiveUntil = null,
    ): array {
        return array_merge($rule, [
            'source_level' => $sourceLevel,
            'source_ref' => $sourceRef,
            'override_mode' => $overrideMode,
            'resolution_chain' => $resolutionChain,
            'rule_type' => $ruleType ?? 'contribution',
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
        ]);
    }

    /**
     * Apply an override rule to the resolved set according to override_mode.
     *
     * - replace: replaces the existing rule for this rule_key
     * - supplement: adds the rule as a new entry (appended with unique key)
     * - minimum: keeps the most favorable value (highest total rate for employee)
     */
    private function applyOverride(array $resolved, string $ruleKey, array $newRule, string $overrideMode): array
    {
        switch ($overrideMode) {
            case 'replace':
                $resolved[$ruleKey] = $newRule;
                break;

            case 'supplement':
                // Add as additional rule — use a unique key to avoid collision
                $supplementKey = $ruleKey . '_' . $newRule['source_level'];
                $resolved[$supplementKey] = $newRule;
                break;

            case 'minimum':
                // "Most favorable" = highest total rate (employee_rate + employer_rate)
                // This implements the principe de faveur: keep whichever is better for the employee
                if (isset($resolved[$ruleKey])) {
                    $existingTotal = ($resolved[$ruleKey]['employee_rate_bps'] ?? 0) + ($resolved[$ruleKey]['employer_rate_bps'] ?? 0);
                    $newTotal = ($newRule['employee_rate_bps'] ?? 0) + ($newRule['employer_rate_bps'] ?? 0);

                    if ($newTotal > $existingTotal) {
                        $resolved[$ruleKey] = $newRule;
                    }
                    // else: keep existing (more favorable)
                } else {
                    $resolved[$ruleKey] = $newRule;
                }
                break;

            default:
                $resolved[$ruleKey] = $newRule;
        }

        return $resolved;
    }
}
