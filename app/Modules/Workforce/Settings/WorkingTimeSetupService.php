<?php

namespace App\Modules\Workforce\Settings;

use App\Core\Audit\AuditLogger;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Workforce\ConventionCollective;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use App\Modules\Workforce\Support\WeekStartResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-799 — the COMPANY configures how it organises working time (step « Temps de travail » of the
 * existing Workforce assistant). Five sections : A framework (market, convention CONFIRMED by the
 * company), B rhythm, C rest, D hours, E behaviour. Pure orchestration of existing engines :
 * WorkforceRuleResolver (one resolution, legal bounds) + WeekStartResolver ; the single control point
 * stays ShiftRuleValidator. Every field says honestly whether it is CONTROLLED, only CONFIGURED, or
 * LEGALLY UNVERIFIED ; no legal value is invented, nothing is applied to existing companies.
 */
final class WorkingTimeSetupService
{
    /** field => [section, store (worktime|break|organization|week_start), label key] */
    private const FIELDS = [
        'work_days_per_week' => ['rhythm', 'organization'],
        'fixed_work_days' => ['rhythm', 'organization'],
        'weekly_hours_reference' => ['rhythm', 'worktime'],
        'week_start_day' => ['rhythm', 'week_start'],
        'daily_rest_min_hours' => ['rest', 'worktime'],
        'weekly_rest_min_hours' => ['rest', 'worktime'],
        'max_consecutive_work_days' => ['rest', 'organization'],
        'weekly_rest_days' => ['rest', 'organization'],
        'consecutive_rest_days' => ['rest', 'organization'],
        'shift_target_hours' => ['hours', 'organization'],
        'target_duration_minutes' => ['hours', 'break'],
        'night_work_start' => ['hours', 'worktime'],
        'night_work_end' => ['hours', 'worktime'],
        'consecutive_days_behavior' => ['behavior', 'organization'],
        'enforcement' => ['behavior', 'break'],
        'early_resume_policy' => ['behavior', 'break'],
    ];

    public function __construct(private Company $company) {}

    public function describe(): array
    {
        $r = app(WorkforceRuleResolver::class);
        $resolved = ['worktime' => $r->resolveWorkTimeRules($this->company), 'break' => $r->resolveBreakRules($this->company),
            'organization' => $r->resolveOrganizationRules($this->company)];
        $week = WeekStartResolver::describe($this->company, true);
        $sections = ['rhythm' => [], 'rest' => [], 'hours' => [], 'behavior' => []];
        foreach (self::FIELDS as $key => [$section, $store]) {
            if ($store === 'week_start') {
                $sections[$section][] = ['key' => $key, 'value' => $this->company->week_start_day, 'effective' => $week['week_start_day'] ?? null,
                    'source' => $this->company->week_start_day === null ? 'default' : 'company', 'status' => 'configured', 'legal' => null, 'options' => [0, 1, 2, 3, 4, 5, 6]];

                continue;
            }
            $rule = $resolved[$store][$this->ruleKey($key)] ?? null;
            $legal = collect($rule['chain'] ?? [])->firstWhere('layer', 'market_law');
            $sections[$section][] = [
                'key' => $key, 'value' => $rule['value'] ?? null, 'source' => $rule['source'] ?? 'to_complete', 'mutability' => $rule['mutability'] ?? 'configurable',
                // Légal / conventionnel : valeur et référence vérifiées, jamais inventées.
                'legal' => $legal ? ['value' => $legal['value'], 'reference' => $legal['reference'] ?? null] : null,
                'conflicts' => $rule['conflicts'] ?? [],
                'status' => $this->status($key, $rule, $legal),
                'options' => $this->options($key),
            ];
        }

        return ['framework' => $this->framework(), 'sections' => $sections,
            'contracts' => $this->contractVariants(), 'not_supported' => ['rotations', 'per_site_rules', 'per_population_rhythm']];
    }

    /** A : market + conventions relevant to the trade (suggested) vs the one the company CONFIRMED. */
    public function framework(): array
    {
        $jd = Jobdomain::where('key', $this->company->jobdomain_key)->first();
        $market = $this->company->market_key;
        $suggestedIds = collect($jd?->convention_refs ?? [])->where('market_key', $market)->pluck('convention_collective_id')->filter()->all();
        $available = ConventionCollective::active()->forMarket((string) $market)->orderBy('short_name')->get();
        $present = fn ($cc) => $cc ? ['id' => $cc->id, 'idcc' => $cc->idcc, 'name' => $cc->short_name, 'full_name' => $cc->full_name, 'verified' => $cc->verified_at !== null,
            'version' => $cc->version_label, 'effective_from' => $cc->effective_from?->toDateString(), 'source' => $cc->legal_source_url,
            'verified_rules' => $cc->rules()->whereNotNull('verified_at')->count(), 'rules' => $cc->rules()->count()] : null;
        $legalRules = DB::table('market_rule_sets')->where('market_key', $market)->where('domain', 'workforce')->get(['rule_key', 'reference']);

        return [
            'market' => $market,
            'legal_check' => $legalRules->whereNotNull('reference')->isNotEmpty() ? 'available' : 'unavailable',
            'legal_rules' => $legalRules->map(fn ($l) => ['key' => $l->rule_key, 'reference' => $l->reference])->values()->all(),
            'suggested' => $available->whereIn('id', $suggestedIds)->map($present)->values()->all(),
            'available' => $available->map($present)->values()->all(),
            'current' => $present($this->company->defaultConventionCollective ?? null),
            'confirmed' => $this->company->convention_confirmed_at !== null,
            'confirmed_at' => $this->company->convention_confirmed_at?->toIso8601String(),
            // Une convention renseignée sans confirmation (historique) reste lue par le moteur, signalée.
            'unconfirmed_legacy' => $this->company->default_convention_collective_id !== null && $this->company->convention_confirmed_at === null,
        ];
    }

    /** The company confirms its applicable convention (or that none applies). Never deduced from the JobDomain. */
    public function confirmConvention(?int $conventionId, ?int $actorId): array
    {
        if ($conventionId !== null) {
            ConventionCollective::active()->forMarket((string) $this->company->market_key)->findOrFail($conventionId);
        }
        $this->company->forceFill(['default_convention_collective_id' => $conventionId, 'convention_confirmed_at' => now(), 'convention_confirmed_by' => $actorId])->save();
        app(AuditLogger::class)->logCompany($this->company->id, 'workforce.convention_confirmed', 'company', (string) $this->company->id,
            ['actorId' => $actorId, 'metadata' => ['convention_collective_id' => $conventionId]]);

        return $this->framework();
    }

    /** All-or-nothing save : every value goes through the resolver's own guards (legal bounds, ranges). */
    /** ADR-848 : named saveValues (was `save`, read as an Eloquent save by the controller invariant). */
    public function saveValues(array $values, ?int $actorId): array
    {
        $errors = [];
        DB::transaction(function () use ($values, &$errors) {
            $r = app(WorkforceRuleResolver::class);
            foreach ($values as $key => $value) {
                if (! isset(self::FIELDS[$key])) {
                    $errors[$key] = __('working_time.unknown');

                    continue;
                }
                try {
                    match (self::FIELDS[$key][1]) {
                        'worktime' => $r->setWorkTimeRule($this->company, $key, $value),
                        'break' => $r->setBreakRule($this->company, $key, $value),
                        'organization' => $r->setOrganizationRule($this->company, $key, $value),
                        'week_start' => $this->company->forceFill(['week_start_day' => $value === null ? null : (int) $value])->save(),
                    };
                } catch (\DomainException $e) {
                    $m = $e->getMessage();
                    $errors[$key] = match (true) {
                        str_contains($m, 'less favorable') => __('working_time.less_favorable'),
                        str_contains($m, 'must be between') => __('working_time.out_of_range'),
                        str_contains($m, 'must be one of') => __('working_time.not_allowed'),
                        str_contains($m, 'mandatory') => __('working_time.mandatory'),
                        default => $m,
                    };
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors); // annule tout
            }
        });
        app(AuditLogger::class)->logCompany($this->company->id, 'workforce.working_time_configured', 'company', (string) $this->company->id,
            ['actorId' => $actorId, 'metadata' => ['keys' => array_keys($values)]]);

        return $this->describe();
    }

    private function ruleKey(string $field): string
    {
        return $field;
    }

    /** controlled (by the single engine) · configured (kept, not controlled yet) · legal_unverified. */
    private function status(string $key, ?array $rule, ?array $legal): string
    {
        if (isset(WorkforceRuleResolver::CONTROLS[$key])) {
            if (in_array($key, ['daily_rest_min_hours', 'daily_hours_max', 'weekly_hours_max', 'weekly_rest_min_hours'], true) && ! $legal) {
                return 'legal_unverified';
            }

            return ($rule['value'] ?? null) === null ? 'not_set' : 'controlled';
        }

        return 'configured';
    }

    private function options(string $key): ?array
    {
        return match ($key) {
            'work_days_per_week' => [4, 5, 6],
            'fixed_work_days' => [0, 1, 2, 3, 4, 5, 6],
            'consecutive_days_behavior' => ['inform', 'warn', 'block', 'approve'],
            'enforcement' => ['signaled', 'required'],
            'early_resume_policy' => ['allow', 'warn', 'block', 'approve'],
            default => null,
        };
    }

    /** B : contracts whose rhythm differs from the company reference (the contract prevails for its holder). */
    private function contractVariants(): array
    {
        $ref = app(WorkforceRuleResolver::class)->resolveWorkTimeRules($this->company)['weekly_hours_reference']['value'] ?? null;
        $contracts = EmploymentContract::withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_current', true)->whereIn('status', ['active', 'signed'])->get(['weekly_hours', 'convention_collective_id']);

        return ['current' => $contracts->count(),
            'different_weekly_hours' => $ref === null ? 0 : $contracts->filter(fn ($c) => $c->weekly_hours !== null && (float) $c->weekly_hours !== (float) $ref)->count(),
            'own_convention' => $contracts->whereNotNull('convention_collective_id')->filter(fn ($c) => (int) $c->convention_collective_id !== (int) $this->company->default_convention_collective_id)->count()];
    }
}
