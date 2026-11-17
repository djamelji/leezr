<?php

namespace App\Core\Requirements;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Support\Collection;

/**
 * ADR-791 (L1) — PARALLEL resolution of field and document requirements, with explanation.
 *
 * NOT CONSUMED by any screen, request, form, notification or permission : the operational source of
 * truth stays DocumentResolverService::requirementMap / FieldResolverService (ADR-790). Read-only.
 *
 * Applicability uses business context only — position (JobRole), department, contract type, market,
 * active modules — NEVER the RBAC role. A person without position simply does not match
 * position-scoped requirements (no position is ever inferred).
 *
 * Batch : rows of the company (and its shared Platform / market / JobDomain / module rows) loaded once,
 * subjects loaded once ; resolution is in memory (no query per person nor per requirement).
 *
 * Priority rules (deterministic) :
 *  R1  only ACTIVE rows in their effective window count ; a module row counts only if the module is
 *      active for the company ; a preset row without company, or a module PROPOSAL (is_template), is a
 *      TEMPLATE (counts only once copied) ;
 *      a JobDomain / market row only for the company's JobDomain / market.
 *  R2  applicability must match the subject (absent dimension = applies to everyone).
 *  R3  the strongest level wins (recommended < default < configurable_required < imperative) ;
 *      several sources for the same target = ONE requirement, every source kept in `contributions`.
 *  R4  equal level : precedence platform > market > company > module > preset > jobdomain, then id.
 *  R5  a company WAIVER neutralises every level up to configurable_required ; never `imperative`
 *      (the waiver is then reported as overridden).
 *  R6  required = effective level ≥ default and not waived ; `recommended` is only a suggestion.
 *  R7  a company row copied from a template (derived_from) whose template now has a higher version
 *      reports `update_available` (never applied automatically) ; customised copies stay untouched.
 */
final class EffectiveRequirementResolver
{
    private const PRECEDENCE = ['platform' => 6, 'market' => 5, 'company' => 4, 'module' => 3, 'preset' => 2, 'jobdomain' => 1];

    /** @var array<string, bool> */
    private array $moduleActive = [];

    /** @var array<int, true> document types scoped to the COMPANY itself (never to a person) */
    private array $companyScopedDocuments = [];

    /** @param Collection<int, Requirement> $rows */
    private function __construct(private Company $company, private Collection $rows, private Collection $templates)
    {
        $docIds = $rows->pluck('document_type_id')->filter()->unique()->all();
        $this->companyScopedDocuments = $docIds === [] ? [] : array_fill_keys(
            \App\Core\Documents\DocumentType::whereIn('id', $docIds)->where('scope', \App\Core\Documents\DocumentType::SCOPE_COMPANY)->pluck('id')->all(), true);
    }

    /**
     * @param  iterable<Requirement>|null  $rows  explicit rows (in-memory projection) ; null = the registry
     */
    public static function forCompany(Company $company, ?iterable $rows = null): self
    {
        if ($rows === null) {
            $all = Requirement::query()
                ->where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))
                ->where('subject_scope', Requirement::SUBJECT_PERSON) // ADR-798 : exigences de PERSONNE uniquement
                ->get();
        } else {
            $all = collect($rows)->filter(fn (Requirement $r) => ($r->company_id === null || (int) $r->company_id === (int) $company->id)
                && ($r->subject_scope ?? Requirement::SUBJECT_PERSON) === Requirement::SUBJECT_PERSON)->values();
        }

        // Preset rows without company and module PROPOSALS (is_template, ADR-794) are templates : they
        // never apply by themselves (R1) ; only a company copy (adoption) counts.
        $isTemplate = fn (Requirement $r) => $r->company_id === null && ($r->source === 'preset' || $r->is_template);
        $templates = $all->filter($isTemplate);

        return new self($company, $all->reject($isTemplate)->values(), $templates);
    }

    /**
     * Subjects of the company in one pass : employees (with or without account) + accounts without
     * employee record. Context = business dimensions only.
     *
     * @return list<array{subject: string, employee_id: ?int, user_id: ?int, job_role_id: ?int, job_role_key: ?string, department_id: ?int, contract_type: ?string}>
     */
    public function subjects(): array
    {
        $employees = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereNotIn('status', ['terminated'])->with('jobRole:id,preset_item_key')->get(['id', 'user_id', 'job_role_id', 'department_id']);
        $contracts = EmploymentContract::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('is_current', true)->whereIn('status', [EmploymentContract::STATUS_ACTIVE, EmploymentContract::STATUS_SUSPENDED])
            ->pluck('contract_type', 'employee_id');

        $out = [];
        $seenUsers = [];
        foreach ($employees as $e) {
            $out[] = $this->subjectFromEmployee($e, $contracts[$e->id] ?? null);
            if ($e->user_id) {
                $seenUsers[(int) $e->user_id] = true;
            }
        }
        foreach ($this->company->memberships()->pluck('user_id') as $userId) {
            if (! isset($seenUsers[(int) $userId])) {
                $out[] = ['subject' => 'user:'.$userId, 'employee_id' => null, 'user_id' => (int) $userId, 'job_role_id' => null, 'job_role_key' => null, 'department_id' => null, 'contract_type' => null];
            }
        }

        return $out;
    }

    /** ADR-792 — a position seen as a subject (configuration preview) : no person, no account. */
    public static function subjectForJobRole(?\App\Core\Workforce\JobRole $job): array
    {
        return ['subject' => $job ? 'job_role:'.$job->id : 'everyone', 'employee_id' => null, 'user_id' => null,
            'job_role_id' => $job?->id, 'job_role_key' => $job?->preset_item_key, 'department_id' => null, 'contract_type' => null];
    }

    public static function subjectFromEmployee(Employee $e, ?string $contractType): array
    {
        return [
            'subject' => $e->user_id ? 'user:'.$e->user_id : 'employee:'.$e->id, // sujet canonique (ADR-761)
            'employee_id' => (int) $e->id,
            'user_id' => $e->user_id ? (int) $e->user_id : null,
            'job_role_id' => $e->job_role_id ? (int) $e->job_role_id : null,
            'job_role_key' => $e->jobRole?->preset_item_key,
            'department_id' => $e->department_id ? (int) $e->department_id : null,
            'contract_type' => $contractType,
        ];
    }

    /**
     * Effective requirements of one subject, keyed by target (« document:12 », « field:7 »).
     *
     * @return array<string, array{target_kind: string, target_id: int, required: bool, level: ?string, waived: bool,
     *   retained: ?array, contributions: list<array>, customizable: bool, update_available: ?array}>
     */
    public function resolveFor(array $subject, ?\DateTimeInterface $at = null): array
    {
        $at ??= now();
        $byTarget = [];
        foreach ($this->rows as $row) {
            if ($row->document_type_id !== null && isset($this->companyScopedDocuments[$row->document_type_id])) {
                continue; // pièce de l'entreprise (K-bis, assurance…) : jamais exigée d'une personne
            }
            $byTarget[$row->targetKey()][] = $row;
        }

        $result = [];
        foreach ($byTarget as $key => $rows) {
            $contributions = [];
            $requires = [];
            $waivers = [];
            foreach ($rows as $row) {
                $status = $this->statusOf($row, $subject, $at);
                $contributions[] = $this->describe($row, $status);
                if ($status !== 'applicable') {
                    continue;
                }
                ($row->effect ?? Requirement::EFFECT_REQUIRE) === Requirement::EFFECT_WAIVE ? $waivers[] = $row : $requires[] = $row;
            }
            if ($requires === [] && $waivers === []) {
                continue; // aucune source applicable à cette personne
            }

            usort($requires, fn ($a, $b) => [RequirementLevel::rank($b->level), self::PRECEDENCE[$b->source], -$b->id] <=> [RequirementLevel::rank($a->level), self::PRECEDENCE[$a->source], -$a->id]);
            $retained = $requires[0] ?? null;
            $level = $retained?->level;
            $imperative = $level === RequirementLevel::IMPERATIVE;
            $waived = $waivers !== [] && ! $imperative;

            foreach ($contributions as &$c) {
                if ($c['status'] !== 'applicable') {
                    continue;
                }
                if ($retained && $c['id'] === $retained->id && ! $waived) {
                    $c['decision'] = 'retained';
                } elseif ($c['effect'] === Requirement::EFFECT_WAIVE) {
                    $c['decision'] = $waived ? 'retained' : 'overridden_by_imperative';
                } else {
                    $c['decision'] = $waived ? 'waived_by_company' : 'merged'; // même exigence, provenance conservée
                }
            }
            unset($c);

            [$kind, $id] = explode(':', $key);
            $result[$key] = [
                'target_kind' => $kind,
                'target_id' => (int) $id,
                'required' => ! $waived && RequirementLevel::requires($level),
                'level' => $level,
                'waived' => $waived,
                'retained' => $retained && ! $waived ? $this->describe($retained, 'applicable') : ($waived ? $this->describe($waivers[0], 'applicable') : null),
                'contributions' => $contributions,
                'customizable' => ! $imperative,
                'update_available' => $retained ? $this->updateFor($retained) : null,
            ];
        }

        return $result;
    }

    private function statusOf(Requirement $r, array $subject, \DateTimeInterface $at): string
    {
        if ($r->status !== Requirement::STATUS_ACTIVE) {
            return $r->status; // inactive | superseded
        }
        if (($r->effective_from && $r->effective_from > $at) || ($r->effective_until && $r->effective_until <= $at)) {
            return 'out_of_window';
        }
        if ($r->source === 'module' && ! $this->moduleIsActive((string) $r->source_ref)) {
            return 'module_inactive';
        }
        if ($r->source === 'jobdomain' && $r->source_ref !== $this->company->jobdomain_key) {
            return 'other_jobdomain';
        }
        if ($r->source === 'market' && $r->source_ref !== $this->company->market_key) {
            return 'other_market';
        }

        return $this->applies($r->applicability ?? [], $subject) ? 'applicable' : 'not_applicable';
    }

    private function applies(array $a, array $s): bool
    {
        $match = fn (string $dim, $value) => empty($a[$dim]) || ($value !== null && in_array($value, $a[$dim], false));

        return $match('job_role_keys', $s['job_role_key'] ?? null)
            && $match('job_role_ids', $s['job_role_id'] ?? null)
            && $match('department_ids', $s['department_id'] ?? null)
            && $match('contract_types', $s['contract_type'] ?? null)
            && $match('markets', $this->company->market_key);
    }

    private function moduleIsActive(string $key): bool
    {
        return $this->moduleActive[$key] ??= ModuleGate::isActive($this->company, $key);
    }

    private function updateFor(Requirement $r): ?array
    {
        if ($r->derived_from_id === null || $r->customized_at !== null) {
            return null; // pas une copie, ou copie personnalisée : jamais mise à jour d'office
        }
        $latest = $this->templates->merge($this->rows->whereNull('company_id'))
            ->filter(fn (Requirement $t) => $t->source === $r->source && $t->source_ref === $r->source_ref && $t->targetKey() === $r->targetKey() && $t->status === Requirement::STATUS_ACTIVE)
            ->sortByDesc('source_version')->first();

        return $latest && (int) $latest->source_version > (int) $r->source_version
            ? ['from_version' => $r->source_version, 'to_version' => $latest->source_version, 'level' => $latest->level, 'requires_confirmation' => true]
            : null;
    }

    private function describe(Requirement $r, string $status): array
    {
        return [
            'id' => $r->id, 'source' => $r->source, 'source_ref' => $r->source_ref, 'source_version' => $r->source_version,
            'effect' => $r->effect ?? Requirement::EFFECT_REQUIRE, 'level' => $r->level, 'status' => $status,
            'customized' => $r->customized_at !== null, 'legal_reference' => $r->legal_reference, 'reason' => $r->reason,
            'applicability' => $r->applicability ?? [], 'verified_at' => $r->verified_at?->toDateString(),
            'derived_from_id' => $r->derived_from_id, 'stage' => $r->stage, // ADR-819 : moment de collecte
        ];
    }
}
