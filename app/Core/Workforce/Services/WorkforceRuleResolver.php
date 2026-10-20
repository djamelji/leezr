<?php

namespace App\Core\Workforce\Services;

use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Policies\CompanyPolicy;
use Carbon\Carbon;

/**
 * WorkforceRuleResolver — EXPLAINABLE resolution of Workforce work-time / break rules.
 *
 * This is NOT a parallel engine: it reuses the existing effective-dated stores
 *   - MarketRuleSet (domain='workforce')  → legal baseline (source='law', Code du travail ref)
 *   - CompanyPolicy  (domain='workforce') → per-Company configurable overrides (effective-dated)
 * and mirrors PayrollRuleResolver's provenance pattern. It adds, for each resolved rule, the
 * information the configuration cockpit needs to EXPLAIN it to an admin:
 *   - value       : the effective value (or null when undocumented)
 *   - source      : market_law | company | default         (provenance)
 *   - reference   : legal/convention reference when applicable
 *   - mutability  : mandatory | configurable | to_complete  (can the admin change it?)
 *   - status      : configured | default | to_complete      (is a real value in place?)
 *   - effective_from
 *
 * Hard rule (mission §2): legal values are NEVER invented. When a dimension has no documented
 * legal value and no sensible operational default, the rule is returned as `to_complete`
 * (value null) rather than a fabricated number. Operational defaults (e.g. breaks excluded from
 * effective worktime) are tagged source='default' — an operational convention, not a legal claim.
 *
 * Rules are isolated per Company (CompanyScope on CompanyPolicy); one Company's override never
 * affects another's resolution.
 */
class WorkforceRuleResolver
{
    public const DOMAIN = 'workforce';

    /**
     * Resolve the full BREAK rule spec for a company at a date, with provenance.
     *
     * @return array<string, array{value: mixed, source: string, reference: ?string, mutability: string, status: string, effective_from: ?string}>
     */
    public function resolveBreakRules(Company $company, ?Carbon $at = null): array
    {
        $at ??= now();

        // Legal baseline from the Market rule set (already seeded: break_after_hours L3121-16).
        $legalBreak = $this->marketRule($company, 'break_after_hours', $at);
        $legalMin = is_array($legalBreak?->value) ? ($legalBreak->value['break_min'] ?? null) : null;
        $legalTriggerHours = is_array($legalBreak?->value) ? ($legalBreak->value['amount'] ?? null) : null;
        $legalRef = $legalBreak?->reference;
        $legalFrom = $legalBreak?->effective_from?->toDateString();

        $rules = [];

        // ── Mandatory legal floors (admin may be MORE favorable, never less) ──────────────
        $rules['min_duration_minutes'] = $legalMin !== null
            ? $this->rule($legalMin, 'market_law', $legalRef, 'mandatory', 'configured', $legalFrom)
            : $this->toComplete('No documented legal minimum break for this market.');

        $rules['trigger_after_minutes'] = $legalTriggerHours !== null
            ? $this->rule((int) $legalTriggerHours * 60, 'market_law', $legalRef, 'mandatory', 'configured', $legalFrom)
            : $this->toComplete('No documented legal break trigger for this market.');

        // ── Company-configurable rules (CompanyPolicy overrides; sensible operational defaults) ──
        // Target duration: what the UI counts down to. Defaults to the legal minimum when unset.
        $target = $this->companyPolicy($company, 'break_target_minutes', $at);
        $rules['target_duration_minutes'] = $target !== null
            ? $this->rule((int) $target, 'company', null, 'configurable', 'configured')
            : $this->rule($legalMin ?? null, $legalMin !== null ? 'default' : 'to_complete', $legalRef,
                'configurable', $legalMin !== null ? 'default' : 'to_complete');

        $rules['max_duration_minutes'] = $this->configurable($company, 'break_max_minutes', $at, null);
        $rules['max_count'] = $this->configurable($company, 'break_max_count', $at, null);
        $rules['earliest_start_after_minutes'] = $this->configurable($company, 'break_earliest_after_minutes', $at, null);
        $rules['latest_start_before_minutes'] = $this->configurable($company, 'break_latest_before_minutes', $at, null);

        // Operational defaults (NOT legal claims): excluded from effective worktime, unpaid,
        // signalled (not blocking), splittable; the admin can change any of these.
        $rules['included_in_worktime'] = $this->configurableBool($company, 'break_included_in_worktime', $at, false);
        $rules['paid'] = $this->configurableBool($company, 'break_paid', $at, false);
        $rules['splittable'] = $this->configurableBool($company, 'break_splittable', $at, true);
        $rules['enforcement'] = $this->configurableEnum($company, 'break_enforcement', $at, 'signaled',
            ['signaled', 'required']);
        $rules['early_resume_policy'] = $this->configurableEnum($company, 'break_early_resume_policy', $at, 'warn',
            ['allow', 'warn', 'block', 'approve']);

        return $rules;
    }

    /** Convenience: the effective break target in minutes (what the countdown targets), or null. */
    public function breakTargetMinutes(Company $company, ?Carbon $at = null): ?int
    {
        $spec = $this->resolveBreakRules($company, $at);
        $v = $spec['target_duration_minutes']['value'] ?? null;

        return $v !== null ? (int) $v : null;
    }

    /**
     * Set a Company break-rule override, enforcing mutability + legal bounds.
     * Rejects attempts to configure a mandatory rule, or to set a target below the legal minimum.
     *
     * @throws \DomainException
     */
    public function setBreakRule(Company $company, string $ruleKey, mixed $value, ?Carbon $at = null): CompanyPolicy
    {
        $at ??= now();
        $spec = $this->resolveBreakRules($company, $at);

        if (! array_key_exists($ruleKey, $spec)) {
            throw new \DomainException("Unknown break rule '{$ruleKey}'.");
        }
        if ($spec[$ruleKey]['mutability'] === 'mandatory') {
            throw new \DomainException("Break rule '{$ruleKey}' is mandatory (legal) and cannot be overridden.");
        }

        // Legal-bound check: target/max must be >= legal minimum break.
        $legalMin = $spec['min_duration_minutes']['value'] ?? null;
        if ($legalMin !== null && in_array($ruleKey, ['target_duration_minutes', 'max_duration_minutes'], true)
            && $value !== null && (int) $value < (int) $legalMin) {
            throw new \DomainException("'{$ruleKey}' ({$value}) cannot be below the legal minimum break ({$legalMin} min).");
        }

        $policyKey = $this->policyKeyFor($ruleKey);

        return app(\App\Core\Policies\CompanyPolicyResolver::class)
            ->set($company->id, self::DOMAIN, $policyKey, $value, 'admin_manual', $at);
    }

    // ── internals ────────────────────────────────────────────────────────────────────────

    private function marketRule(Company $company, string $ruleKey, Carbon $at): ?MarketRuleSet
    {
        if (! $company->market_key) {
            return null;
        }

        return MarketRuleSet::where('market_key', $company->market_key)
            ->domain(self::DOMAIN)
            ->forRule($ruleKey)
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();
    }

    private function companyPolicy(Company $company, string $policyKey, Carbon $at): mixed
    {
        $p = CompanyPolicy::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->domain(self::DOMAIN)
            ->forKey($policyKey)
            ->activeAt($at)
            ->orderByDesc('effective_from')
            ->first();

        return $p?->value;
    }

    private function policyKeyFor(string $ruleKey): string
    {
        return [
            'target_duration_minutes' => 'break_target_minutes',
            'max_duration_minutes' => 'break_max_minutes',
            'max_count' => 'break_max_count',
            'earliest_start_after_minutes' => 'break_earliest_after_minutes',
            'latest_start_before_minutes' => 'break_latest_before_minutes',
            'included_in_worktime' => 'break_included_in_worktime',
            'paid' => 'break_paid',
            'splittable' => 'break_splittable',
            'enforcement' => 'break_enforcement',
            'early_resume_policy' => 'break_early_resume_policy',
        ][$ruleKey] ?? $ruleKey;
    }

    private function rule(mixed $value, string $source, ?string $reference, string $mutability, string $status, ?string $from = null): array
    {
        return compact('value', 'source', 'reference', 'mutability', 'status') + ['effective_from' => $from];
    }

    private function toComplete(string $reference): array
    {
        return $this->rule(null, 'to_complete', $reference, 'to_complete', 'to_complete');
    }

    /** Configurable numeric with a sensible operational default (null → optional, stays 'default'). */
    private function configurable(Company $company, string $policyKey, Carbon $at, mixed $default): array
    {
        $v = $this->companyPolicy($company, $policyKey, $at);
        if ($v !== null) {
            return $this->rule($v, 'company', null, 'configurable', 'configured');
        }

        return $this->rule($default, 'default', null, 'configurable', 'default');
    }

    private function configurableBool(Company $company, string $policyKey, Carbon $at, bool $default): array
    {
        $v = $this->companyPolicy($company, $policyKey, $at);
        if ($v !== null) {
            return $this->rule((bool) $v, 'company', null, 'configurable', 'configured');
        }

        return $this->rule($default, 'default', null, 'configurable', 'default');
    }

    private function configurableEnum(Company $company, string $policyKey, Carbon $at, string $default, array $allowed): array
    {
        $v = $this->companyPolicy($company, $policyKey, $at);
        if ($v !== null && in_array($v, $allowed, true)) {
            return $this->rule($v, 'company', null, 'configurable', 'configured');
        }

        return $this->rule($default, 'default', null, 'configurable', 'default');
    }
}
