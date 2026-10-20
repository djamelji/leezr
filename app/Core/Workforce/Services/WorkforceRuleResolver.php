<?php

namespace App\Core\Workforce\Services;

use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Policies\CompanyPolicy;
use App\Core\Workforce\ConventionCollectiveRule;
use App\Core\Workforce\EmploymentContract;
use Carbon\Carbon;

/**
 * WorkforceRuleResolver — EXPLAINABLE, NORMATIVE resolution of Workforce work-time / break rules.
 *
 * NOT a parallel engine: it reuses the existing effective-dated stores
 *   - MarketRuleSet (domain='workforce')     → legal baseline (source='law', Code du travail ref)
 *   - ConventionCollectiveRule (working_time) → applicable CC (contract or Company default)
 *   - CompanyPolicy (domain='workforce')      → per-Company configurable overrides
 * and mirrors PayrollRuleResolver's provenance/override-mode pattern.
 *
 * Resolution is NORMATIVE, not a blind override chain. Each rule declares a `nature`:
 *   - legal_min  : a legal FLOOR. More-favorable = higher. Only raised by CC/Company; a layer
 *                  proposing a lower (less favorable) value is IGNORED and flagged as a conflict.
 *   - legal_max  : a legal CEILING. More-favorable = lower. Only lowered.
 *   - fixed_legal: mandatory, not overridable (e.g. the break trigger threshold).
 *   - config     : no legal floor/ceiling — highest-priority layer with a value wins
 *                  (Company > CC > JobDomain > default), clamped to any legal bound.
 *
 * Dimensions (one Company has exactly one JobDomain; a JobDomain does NOT impose a single CC):
 *   Market(legal) → Convention Collective → JobDomain → Company → Contract → Individual, by date.
 *
 * Every resolved rule returns value, source (market_law|convention|jobdomain|company|default|
 * to_complete), legal reference, mutability, status, effective_from, and the full resolution
 * chain (for the cockpit to explain the decision and surface conflicts / incomplete config).
 * Legal values are NEVER invented: an undocumented dimension yields `to_complete` (value null).
 */
class WorkforceRuleResolver
{
    public const DOMAIN = 'workforce';

    // ── Rule catalogs ────────────────────────────────────────────────────────────────────
    // nature: legal_min | legal_max | fixed_legal | config ; type: int|minutes|hours|bool|enum
    // market: ['rule' => marketRuleKey, 'path' => dotPathInValue] ; cc: ccRuleKey ; policy: CompanyPolicy key

    private function breakCatalog(): array
    {
        return [
            'min_duration_minutes' => ['nature' => 'legal_min', 'type' => 'int', 'market' => ['rule' => 'break_after_hours', 'path' => 'break_min'], 'cc' => 'break_min_minutes', 'policy' => null],
            'trigger_after_minutes' => ['nature' => 'fixed_legal', 'type' => 'int', 'market' => ['rule' => 'break_after_hours', 'path' => 'amount', 'scale' => 60], 'cc' => null, 'policy' => null],
            'target_duration_minutes' => ['nature' => 'config', 'type' => 'int', 'market' => null, 'cc' => 'break_target_minutes', 'policy' => 'break_target_minutes', 'default_from' => 'min_duration_minutes', 'clamp_min' => 'min_duration_minutes'],
            'max_duration_minutes' => ['nature' => 'config', 'type' => 'int', 'market' => null, 'cc' => 'break_max_minutes', 'policy' => 'break_max_minutes', 'default' => null, 'clamp_min' => 'min_duration_minutes'],
            'max_count' => ['nature' => 'config', 'type' => 'int', 'market' => null, 'cc' => null, 'policy' => 'break_max_count', 'default' => null],
            'earliest_start_after_minutes' => ['nature' => 'config', 'type' => 'int', 'market' => null, 'cc' => null, 'policy' => 'break_earliest_after_minutes', 'default' => null],
            'latest_start_before_minutes' => ['nature' => 'config', 'type' => 'int', 'market' => null, 'cc' => null, 'policy' => 'break_latest_before_minutes', 'default' => null],
            'included_in_worktime' => ['nature' => 'config', 'type' => 'bool', 'market' => null, 'cc' => 'break_paid', 'policy' => 'break_included_in_worktime', 'default' => false],
            'paid' => ['nature' => 'config', 'type' => 'bool', 'market' => null, 'cc' => 'break_paid', 'policy' => 'break_paid', 'default' => false],
            'splittable' => ['nature' => 'config', 'type' => 'bool', 'market' => null, 'cc' => null, 'policy' => 'break_splittable', 'default' => true],
            'enforcement' => ['nature' => 'config', 'type' => 'enum', 'enum' => ['signaled', 'required'], 'market' => null, 'cc' => null, 'policy' => 'break_enforcement', 'default' => 'signaled'],
            'early_resume_policy' => ['nature' => 'config', 'type' => 'enum', 'enum' => ['allow', 'warn', 'block', 'approve'], 'market' => null, 'cc' => null, 'policy' => 'break_early_resume_policy', 'default' => 'warn'],
        ];
    }

    private function workTimeCatalog(): array
    {
        return [
            'weekly_hours_reference' => ['nature' => 'config', 'type' => 'hours', 'market' => ['rule' => 'weekly_hours_legal', 'path' => 'amount'], 'cc' => 'weekly_hours_reference', 'policy' => 'weekly_hours_reference'],
            'daily_hours_max' => ['nature' => 'legal_max', 'type' => 'hours', 'market' => ['rule' => 'daily_hours_max', 'path' => 'amount'], 'cc' => 'daily_hours_max', 'policy' => 'daily_hours_max'],
            'weekly_hours_max' => ['nature' => 'legal_max', 'type' => 'hours', 'market' => ['rule' => 'weekly_hours_max', 'path' => 'amount'], 'cc' => 'weekly_hours_max', 'policy' => 'weekly_hours_max'],
            'daily_rest_min_hours' => ['nature' => 'legal_min', 'type' => 'hours', 'market' => ['rule' => 'rest_daily_min', 'path' => 'amount'], 'cc' => 'daily_rest_min_hours', 'policy' => null],
            'weekly_rest_min_hours' => ['nature' => 'legal_min', 'type' => 'hours', 'market' => ['rule' => 'rest_weekly_min', 'path' => 'amount'], 'cc' => 'weekly_rest_min_hours', 'policy' => null],
            'overtime_daily_threshold_hours' => ['nature' => 'config', 'type' => 'hours', 'market' => null, 'cc' => 'overtime_daily_threshold_hours', 'policy' => 'overtime_daily_threshold_hours', 'default' => null],
            'night_work_start' => ['nature' => 'config', 'type' => 'enum', 'enum' => null, 'market' => null, 'cc' => 'night_work_start', 'policy' => 'night_work_start', 'default' => null],
            'night_work_end' => ['nature' => 'config', 'type' => 'enum', 'enum' => null, 'market' => null, 'cc' => 'night_work_end', 'policy' => 'night_work_end', 'default' => null],
        ];
    }

    // ── Public API ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array> keyed by rule — explainable resolved break spec. */
    public function resolveBreakRules(Company $company, ?Carbon $at = null, ?EmploymentContract $contract = null): array
    {
        return $this->resolveCatalog($company, $this->breakCatalog(), $at, $contract);
    }

    /** @return array<string, array> keyed by rule — explainable resolved work-time spec. */
    public function resolveWorkTimeRules(Company $company, ?Carbon $at = null, ?EmploymentContract $contract = null): array
    {
        return $this->resolveCatalog($company, $this->workTimeCatalog(), $at, $contract);
    }

    public function breakTargetMinutes(Company $company, ?Carbon $at = null, ?EmploymentContract $contract = null): ?int
    {
        $v = $this->resolveBreakRules($company, $at, $contract)['target_duration_minutes']['value'] ?? null;

        return $v !== null ? (int) $v : null;
    }

    /** Set a configurable break-rule Company override, enforcing mutability + legal bounds. */
    public function setBreakRule(Company $company, string $ruleKey, mixed $value, ?Carbon $at = null): CompanyPolicy
    {
        return $this->setRule($company, $this->breakCatalog(), $ruleKey, $value, $at);
    }

    /** Set a configurable work-time Company override, enforcing mutability + legal bounds. */
    public function setWorkTimeRule(Company $company, string $ruleKey, mixed $value, ?Carbon $at = null): CompanyPolicy
    {
        return $this->setRule($company, $this->workTimeCatalog(), $ruleKey, $value, $at);
    }

    // ── Engine ───────────────────────────────────────────────────────────────────────────

    private function resolveCatalog(Company $company, array $catalog, ?Carbon $at, ?EmploymentContract $contract): array
    {
        $at ??= now();
        $resolved = [];
        foreach ($catalog as $key => $spec) {
            $resolved[$key] = $this->resolveRule($company, $key, $spec, $at, $contract, $resolved);
        }

        return $resolved;
    }

    /**
     * Resolve one rule across layers with normative semantics.
     * $alreadyResolved lets a rule reference a sibling (e.g. target defaults to min).
     */
    private function resolveRule(Company $company, string $key, array $spec, Carbon $at, ?EmploymentContract $contract, array $alreadyResolved): array
    {
        $nature = $spec['nature'];
        $chain = [];

        // Layer 1 — Market legal baseline.
        $legal = $this->readMarket($company, $spec, $at);
        if ($legal !== null) {
            $chain[] = ['layer' => 'market_law', 'value' => $legal['value'], 'reference' => $legal['reference']];
        }

        // Layer 2 — Convention collective (applicable to the contract, else Company default).
        $cc = $this->readConvention($company, $spec, $at, $contract);
        if ($cc !== null) {
            $chain[] = ['layer' => 'convention', 'value' => $cc['value'], 'reference' => $cc['reference'], 'override_mode' => $cc['override_mode']];
        }

        // Layer 3 — JobDomain (via CompanyPolicy source=jobdomain_preset); none seeded today.
        $jobdomain = $this->readCompanyPolicy($company, $spec, $at, 'jobdomain_preset');
        if ($jobdomain !== null) {
            $chain[] = ['layer' => 'jobdomain', 'value' => $jobdomain['value']];
        }

        // Layer 4 — Company configurable override.
        $companyVal = $this->readCompanyPolicy($company, $spec, $at, 'admin_manual');
        if ($companyVal !== null) {
            $chain[] = ['layer' => 'company', 'value' => $companyVal['value']];
        }

        // (Layers 5/6 — contract-specific derogations / individual — extensible; the applicable CC
        //  is already selected from the contract above. No per-contract policy store yet.)

        return $this->combine($nature, $spec, $key, $chain, $legal, $cc, $jobdomain, $companyVal, $alreadyResolved);
    }

    private function combine(string $nature, array $spec, string $key, array $chain, ?array $legal, ?array $cc, ?array $jobdomain, ?array $companyVal, array $alreadyResolved): array
    {
        $legalVal = $legal['value'] ?? null;
        $legalRef = $legal['reference'] ?? null;
        $legalFrom = $legal['effective_from'] ?? null;

        if ($nature === 'fixed_legal') {
            return $legalVal !== null
                ? $this->out($legalVal, 'market_law', $legalRef, 'mandatory', 'configured', $legalFrom, $chain)
                : $this->out(null, 'to_complete', null, 'to_complete', 'to_complete', null, $chain);
        }

        if ($nature === 'legal_min' || $nature === 'legal_max') {
            // Start from the legal bound, then apply only MORE-FAVORABLE layer values.
            $best = $legalVal;
            $source = $legalVal !== null ? 'market_law' : 'to_complete';
            $reference = $legalRef;
            $from = $legalFrom;
            $conflicts = [];

            foreach ([['convention', $cc], ['jobdomain', $jobdomain], ['company', $companyVal]] as [$layerName, $layer]) {
                if ($layer === null || $layer['value'] === null) {
                    continue;
                }
                $v = $layer['value'];
                if ($best === null) {
                    $best = $v;
                    $source = $layerName;
                    $reference = $layer['reference'] ?? null;
                    $from = $layer['effective_from'] ?? $from;

                    continue;
                }
                $moreFavorable = $nature === 'legal_min' ? ($v > $best) : ($v < $best);
                if ($moreFavorable) {
                    $best = $v;
                    $source = $layerName;
                    $reference = $layer['reference'] ?? null;
                    $from = $layer['effective_from'] ?? $from;
                } else {
                    // Less favorable than the legal/established bound → ignored + flagged.
                    $conflicts[] = ['layer' => $layerName, 'ignored_value' => $v, 'reason' => 'less_favorable_than_legal'];
                }
            }

            if ($best === null) {
                return $this->out(null, 'to_complete', null, 'to_complete', 'to_complete', null, $chain, $conflicts);
            }
            // legal_min with only the legal layer = mandatory floor; a configurable Company override
            // that is more favorable keeps it adjustable upward. Mutability reflects whether a
            // Company policy key exists for this rule.
            $mutability = $spec['policy'] ? 'configurable' : 'mandatory';

            return $this->out($best, $source, $reference, $mutability, 'configured', $from, $chain, $conflicts);
        }

        // nature === 'config' : highest-priority layer with a value wins (company > jobdomain > cc).
        foreach ([['company', $companyVal], ['jobdomain', $jobdomain], ['convention', $cc]] as [$layerName, $layer]) {
            if ($layer !== null && $layer['value'] !== null) {
                $value = $this->clamp($spec, $layer['value'], $alreadyResolved);

                return $this->out($value, $layerName, $layer['reference'] ?? null, 'configurable', 'configured', $layer['effective_from'] ?? null, $chain);
            }
        }

        // A config rule that has a legal baseline uses it as the (editable) default value.
        if ($legalVal !== null) {
            return $this->out($legalVal, 'market_law', $legalRef, 'configurable', 'default', $legalFrom, $chain);
        }

        // No layer set it → default (sibling-derived, explicit default, or to_complete).
        if (array_key_exists('default_from', $spec)) {
            $def = $alreadyResolved[$spec['default_from']]['value'] ?? null;
            if ($def !== null) {
                return $this->out($def, 'default', null, 'configurable', 'default', null, $chain);
            }
        }
        if (array_key_exists('default', $spec)) {
            $def = $spec['default'];

            return $this->out($def, $def === null ? 'to_complete' : 'default', null, 'configurable', $def === null ? 'to_complete' : 'default', null, $chain);
        }

        return $this->out(null, 'to_complete', null, 'configurable', 'to_complete', null, $chain);
    }

    private function clamp(array $spec, mixed $value, array $alreadyResolved): mixed
    {
        if (isset($spec['clamp_min']) && is_numeric($value)) {
            $min = $alreadyResolved[$spec['clamp_min']]['value'] ?? null;
            if ($min !== null && (int) $value < (int) $min) {
                return (int) $min; // never below the legal floor
            }
        }

        return $value;
    }

    // ── Layer readers ──────────────────────────────────────────────────────────────────────

    private function readMarket(Company $company, array $spec, Carbon $at): ?array
    {
        if (empty($spec['market']) || ! $company->market_key) {
            return null;
        }
        $rule = MarketRuleSet::where('market_key', $company->market_key)
            ->domain(self::DOMAIN)
            ->forRule($spec['market']['rule'])
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();
        if (! $rule) {
            return null;
        }
        $raw = is_array($rule->value) ? ($rule->value[$spec['market']['path']] ?? null) : null;
        if ($raw === null) {
            return null;
        }
        $value = isset($spec['market']['scale']) ? (int) $raw * $spec['market']['scale'] : $raw;

        return ['value' => $value, 'reference' => $rule->reference, 'effective_from' => $rule->effective_from?->toDateString()];
    }

    /** Applicable CC: the contract's, else the Company default. Reads a working_time rule. */
    private function readConvention(Company $company, array $spec, Carbon $at, ?EmploymentContract $contract): ?array
    {
        if (empty($spec['cc'])) {
            return null;
        }
        $ccId = $contract?->convention_collective_id ?? $company->default_convention_collective_id;
        if (! $ccId) {
            return null;
        }
        $rule = ConventionCollectiveRule::where('convention_collective_id', $ccId)
            ->domain(self::DOMAIN)
            ->forRule($spec['cc'])
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();
        if (! $rule) {
            return null;
        }
        $value = is_array($rule->value) ? ($rule->value['amount'] ?? reset($rule->value)) : $rule->value;

        return ['value' => $value, 'reference' => $rule->reference, 'override_mode' => $rule->override_mode, 'effective_from' => $rule->effective_from?->toDateString()];
    }

    private function readCompanyPolicy(Company $company, array $spec, Carbon $at, string $source): ?array
    {
        if (empty($spec['policy'])) {
            return null;
        }
        $p = CompanyPolicy::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->domain(self::DOMAIN)
            ->forKey($spec['policy'])
            ->where('source', $source)
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();
        if (! $p || $p->value === null) {
            return null;
        }

        return ['value' => $p->value, 'effective_from' => $p->effective_from?->toDateString()];
    }

    private function out(mixed $value, string $source, ?string $reference, string $mutability, string $status, ?string $from, array $chain, array $conflicts = []): array
    {
        return [
            'value' => $value,
            'source' => $source,
            'reference' => $reference,
            'mutability' => $mutability,
            'status' => $status,
            'effective_from' => $from,
            'chain' => $chain,
            'conflicts' => $conflicts,
        ];
    }

    // ── Setters (guarded) ────────────────────────────────────────────────────────────────

    private function setRule(Company $company, array $catalog, string $ruleKey, mixed $value, ?Carbon $at): CompanyPolicy
    {
        $at ??= now();
        if (! isset($catalog[$ruleKey])) {
            throw new \DomainException("Unknown rule '{$ruleKey}'.");
        }
        $spec = $catalog[$ruleKey];
        $resolved = $this->resolveCatalog($company, $catalog, $at, null);

        if (($resolved[$ruleKey]['mutability'] ?? null) === 'mandatory' || empty($spec['policy'])) {
            throw new \DomainException("Rule '{$ruleKey}' is mandatory (legal) and cannot be overridden.");
        }
        // legal floor / ceiling bounds.
        if ($value !== null && isset($spec['clamp_min'])) {
            $min = $resolved[$spec['clamp_min']]['value'] ?? null;
            if ($min !== null && (int) $value < (int) $min) {
                throw new \DomainException("'{$ruleKey}' ({$value}) cannot be below the legal minimum ({$min}).");
            }
        }

        return app(\App\Core\Policies\CompanyPolicyResolver::class)
            ->set($company->id, self::DOMAIN, $spec['policy'], $value, 'admin_manual', $at);
    }
}
