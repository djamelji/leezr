<?php

namespace App\Modules\Workforce\Requirements;

use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Requirements\EffectiveRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-792 (L2) — Company configuration of the requirements expected per POSITION (JobRole), in the
 * L1 registry only. PARALLEL MODE : nothing here feeds the operational resolution (ADR-790), requests,
 * compliance, reminders, campaigns, dashboard or employee creation.
 *
 * One company row per (target, scope) ; scope = every collaborator or one position. A change keeps the
 * history (previous row superseded, never updated in place) ; a removal deactivates the company row or,
 * for a requirement coming from another source, records a company WAIVER — refused for an imperative
 * requirement. Preview = the same code inside a transaction always rolled back, then the L1 resolver.
 */
final class RequirementConfigService
{
    /** UI state → registry level */
    public const STATES = ['required' => RequirementLevel::CONFIGURABLE_REQUIRED, 'optional' => RequirementLevel::RECOMMENDED];

    public function __construct(private Company $company, private ?User $actor = null) {}

    // ── Lecture ─────────────────────────────────────────────────────────────

    /** @return list<array{key: string, label: string, job_role_id: ?int, people: int, accountless: int}> */
    public function scopes(): array
    {
        $employees = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', '!=', 'terminated')->get(['id', 'user_id', 'job_role_id']);
        $out = [['key' => 'all', 'label' => null, 'job_role_id' => null, 'people' => $employees->count(), 'accountless' => $employees->whereNull('user_id')->count()]];
        foreach (JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('title')->get(['id', 'title']) as $job) {
            $mine = $employees->where('job_role_id', $job->id);
            $out[] = ['key' => 'job_role:'.$job->id, 'label' => $job->title, 'job_role_id' => $job->id, 'people' => $mine->count(), 'accountless' => $mine->whereNull('user_id')->count()];
        }

        return $out;
    }

    /** Definitions the company may require (catalogues, never duplicated). */
    public function catalog(): array
    {
        $market = $this->company->market_key;
        $inMarket = fn ($rules) => empty($rules['applicable_markets']) || in_array($market, $rules['applicable_markets'], true);

        return [
            'fields' => FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))
                ->get()->filter(fn ($d) => $inMarket($d->validation_rules ?? []))
                ->map(fn ($d) => ['code' => $d->code, 'label' => $d->resolvedLabel(\App\Core\Fields\FieldResolverService::requestLocale()), 'sensitive' => (bool) ($d->validation_rules['sensitive'] ?? false)])
                ->sortBy('label')->values()->all(),
            'documents' => DocumentType::where('scope', DocumentType::SCOPE_COMPANY_USER)->whereNull('archived_at')
                ->where(fn ($q) => $q->where('is_system', true)->orWhere('company_id', $this->company->id))
                ->get()->filter(fn ($t) => $inMarket($t->validation_rules ?? []))
                ->map(fn ($t) => ['code' => $t->code, 'label' => $t->label, 'requires_expiration' => (bool) $t->requires_expiration])
                ->sortBy('label')->values()->all(),
        ];
    }

    /** Effective requirements of a scope, explained (L1 resolver). */
    public function state(string $scope, ?iterable $rows = null): array
    {
        $job = $this->jobRoleOf($scope);
        $resolved = EffectiveRequirementResolver::forCompany($this->company, $rows)->resolveFor(EffectiveRequirementResolver::subjectForJobRole($job));
        $labels = $this->labels();
        $out = ['fields' => [], 'documents' => []];
        foreach ($resolved as $key => $r) {
            if (! isset($labels[$key])) {
                continue;
            }
            $retained = $r['retained'];
            $state = $r['waived'] ? 'waived' : ($r['required'] ? 'required' : 'recommended');
            if ($retained && $retained['source'] === 'company' && $state === 'recommended') {
                $state = 'optional'; // demandé par l'entreprise, sans obligation
            }
            $out[$r['target_kind'] === 'field' ? 'fields' : 'documents'][] = [
                'code' => $labels[$key]['code'],
                'label' => $labels[$key]['label'],
                'state' => $state,
                'origin' => $retained ? $this->origin($retained) : null,
                'sources' => array_values(array_map(fn ($c) => $this->origin($c) + ['decision' => $c['decision'] ?? null], array_filter($r['contributions'], fn ($c) => $c['status'] === 'applicable'))),
                'locked' => ! $r['customizable'],
                'justification' => ! $r['customizable'] ? ($retained['legal_reference'] ?? null) : null,
                'verified_at' => ! $r['customizable'] ? ($retained['verified_at'] ?? null) : null,
                'update_available' => $r['update_available'] !== null,
            ];
        }
        foreach ($out as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return $out;
    }

    // ── Écriture (aperçu = même code, transaction annulée) ───────────────────

    /**
     * @param  list<array{kind: string, code: string, state: string}>  $items  state : required | optional | removed
     */
    public function preview(string $scope, array $items): array
    {
        $before = $this->state($scope);
        DB::beginTransaction();
        try {
            $changes = $this->apply($scope, $items);
            $after = $this->state($scope);
        } finally {
            DB::rollBack(); // aperçu : rien n'est jamais écrit
        }

        return ['changes' => $changes, 'before' => $before, 'after' => $after, 'people' => $this->peopleOf($scope)];
    }

    public function commit(string $scope, array $items): array
    {
        $changes = DB::transaction(fn () => $this->apply($scope, $items));
        if ($changes['added'] || $changes['modified'] || $changes['removed']) {
            app(AuditLogger::class)->logCompany($this->company->id, 'requirements.updated', 'requirements', $scope, [
                'actorId' => $this->actor?->id, 'actorType' => 'user',
                'metadata' => ['scope' => $scope, 'added' => count($changes['added']), 'modified' => count($changes['modified']), 'removed' => count($changes['removed']), 'parallel_mode' => true],
            ]);
        }

        return ['changes' => $changes, 'state' => $this->state($scope)];
    }

    private function apply(string $scope, array $items): array
    {
        $job = $this->jobRoleOf($scope);
        $applicability = $job ? ['job_role_ids' => [$job->id]] : null;
        $changes = ['added' => [], 'modified' => [], 'removed' => [], 'unchanged' => []];

        // Verrou : toutes les lignes Company de la société, pour sérialiser les enregistrements concurrents.
        $companyRows = Requirement::where('company_id', $this->company->id)->where('source', 'company')
            ->where('status', Requirement::STATUS_ACTIVE)->lockForUpdate()->get()
            ->filter(fn (Requirement $r) => $this->scopeKeyOf($r) === $scope);

        foreach ($items as $item) {
            [$kind, $targetColumn, $targetId] = $this->target($item['kind'] ?? '', $item['code'] ?? '');
            $state = $item['state'] ?? '';
            if (! in_array($state, ['required', 'optional', 'removed'], true)) {
                throw ValidationException::withMessages(['items' => __('requirements.invalid_state')]);
            }
            $label = ['kind' => $kind, 'code' => $item['code']];
            $existing = $companyRows->first(fn (Requirement $r) => $r->{$targetColumn} === $targetId && ($r->effect ?? 'require') === 'require');
            $waiver = $companyRows->first(fn (Requirement $r) => $r->{$targetColumn} === $targetId && $r->effect === 'waive');

            if ($state === 'removed') {
                if ($existing) {
                    $this->close($existing, Requirement::STATUS_INACTIVE, 'removed');
                }
                // Encore exigé par une autre source (module, preset, Platform, tous les postes) → dispense tracée.
                $still = $this->resolvedFor($scope, $kind, $targetId);
                if ($still && $still['required']) {
                    if (! $still['customizable']) {
                        throw ValidationException::withMessages(['items' => __('requirements.imperative_locked')]);
                    }
                    if (! $waiver) {
                        $this->create($kind, $targetColumn, $targetId, $applicability, null, Requirement::EFFECT_WAIVE);
                    }
                }
                $existing || ($still && $still['required']) ? $changes['removed'][] = $label : $changes['unchanged'][] = $label;

                continue;
            }

            $level = self::STATES[$state];
            if ($waiver) {
                $this->close($waiver, Requirement::STATUS_INACTIVE, 'waiver_lifted');
            }
            if ($existing && $existing->level === $level) {
                $changes['unchanged'][] = $label; // idempotent

                continue;
            }
            if ($existing) {
                $this->close($existing, Requirement::STATUS_SUPERSEDED, 'modified');
                $this->create($kind, $targetColumn, $targetId, $applicability, $level, Requirement::EFFECT_REQUIRE, $existing);
                $changes['modified'][] = $label + ['from' => $existing->level, 'to' => $level];
            } else {
                $this->create($kind, $targetColumn, $targetId, $applicability, $level, Requirement::EFFECT_REQUIRE);
                $changes['added'][] = $label + ['to' => $level];
            }
        }

        return $changes;
    }

    // ── Reprise de l'existant (lecture seule) ────────────────────────────────

    /**
     * Correspondance entre les règles historiques des RÔLES (doc_config / field_config) et les postes,
     * déduite des postes RÉELS des personnes qui ont ce rôle — jamais du nom du rôle. Rien n'est converti.
     */
    public function legacyMapping(): array
    {
        $jobs = JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('title', 'id');
        $employeesByUser = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->whereNotNull('user_id')->pluck('job_role_id', 'user_id');
        $members = DB::table('memberships')->where('company_id', $this->company->id)->get(['user_id', 'company_role_id'])->groupBy('company_role_id');
        $companyWide = DocumentTypeActivation::where('document_type_activations.company_id', $this->company->id)->where('enabled', true)->where('required_override', true)
            ->join('document_types', 'document_types.id', '=', 'document_type_activations.document_type_id')->pluck('document_types.code')->all();
        $labels = collect($this->labels())->keyBy('code');

        $out = [];
        foreach (CompanyRole::where('company_id', $this->company->id)->get() as $role) {
            $userIds = ($members[$role->id] ?? collect())->pluck('user_id');
            $positions = $userIds->map(fn ($u) => $employeesByUser[$u] ?? null)->filter()->countBy();
            foreach ([['document', $role->doc_config ?? []], ['field', $role->field_config ?? []]] as [$kind, $config]) {
                foreach ($config as $entry) {
                    $hidden = ($entry['visible'] ?? true) === false;
                    $required = ($entry['required'] ?? false) === true;
                    if (! $hidden && ! $required) {
                        continue;
                    }
                    $category = match (true) {
                        $hidden && $kind === 'document' && in_array($entry['code'], $companyWide, true) => 'conflict',
                        $hidden => 'needs_decision',
                        $userIds->isEmpty() || $positions->isEmpty() => 'needs_decision',
                        $positions->sum() < $userIds->count() => 'ambiguous', // une partie seulement a un poste
                        $positions->count() === 1 && $this->alreadyRepresented($kind, $entry['code'], (int) $positions->keys()->first()) => 'certain',
                        $positions->count() === 1 => 'proposed',
                        default => 'ambiguous',
                    };
                    $out[] = [
                        'role' => $role->name, 'kind' => $kind, 'code' => $entry['code'],
                        'label' => $labels[$entry['code']]['label'] ?? $entry['code'],
                        'rule' => $hidden ? 'hidden' : 'required', 'category' => $category,
                        'people' => $userIds->count(), 'people_with_position' => $positions->sum(),
                        'positions' => $positions->map(fn ($n, $id) => ['label' => $jobs[$id] ?? '—', 'people' => $n])->values()->all(),
                    ];
                }
            }
        }

        return $out;
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function alreadyRepresented(string $kind, string $code, int $jobRoleId): bool
    {
        try {
            [, $column, $id] = $this->target($kind, $code);
        } catch (ValidationException) {
            return false;
        }

        return Requirement::where('company_id', $this->company->id)->where('status', Requirement::STATUS_ACTIVE)->where($column, $id)
            ->get()->contains(fn (Requirement $r) => in_array($jobRoleId, $r->applicability['job_role_ids'] ?? [], true));
    }

    private function resolvedFor(string $scope, string $kind, int $targetId): ?array
    {
        $resolved = EffectiveRequirementResolver::forCompany($this->company)->resolveFor(EffectiveRequirementResolver::subjectForJobRole($this->jobRoleOf($scope)));

        return $resolved[$kind.':'.$targetId] ?? null;
    }

    private function create(string $kind, string $column, int $id, ?array $applicability, ?string $level, string $effect, ?Requirement $previous = null): void
    {
        Requirement::create([
            'company_id' => $this->company->id, 'target_kind' => $kind, $column => $id, 'effect' => $effect, 'level' => $level,
            'applicability' => $applicability, 'source' => 'company', 'status' => Requirement::STATUS_ACTIVE, 'effective_from' => now(),
            'derived_from_id' => $previous?->derived_from_id, 'customized_at' => $previous ? now() : null, 'created_by' => $this->actor?->id,
        ]);
    }

    private function close(Requirement $r, string $status, string $reason): void
    {
        $r->forceFill(['status' => $status, 'effective_until' => now(), 'reason' => $reason])->save();
    }

    /** @return array{0: string, 1: string, 2: int} kind, column, id — only definitions visible to THIS company */
    private function target(string $kind, string $code): array
    {
        $catalog = $this->catalog();
        if ($kind === 'field' && collect($catalog['fields'])->contains('code', $code)) {
            $id = FieldDefinition::where('code', $code)->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))->value('id');

            return ['field', 'field_definition_id', (int) $id];
        }
        if ($kind === 'document' && collect($catalog['documents'])->contains('code', $code)) {
            $id = DocumentType::where('code', $code)->where(fn ($q) => $q->where('is_system', true)->orWhere('company_id', $this->company->id))->value('id');

            return ['document', 'document_type_id', (int) $id];
        }
        throw ValidationException::withMessages(['items' => __('requirements.unknown_definition')]);
    }

    public function jobRoleOf(string $scope): ?JobRole
    {
        if ($scope === 'all') {
            return null;
        }
        $id = str_starts_with($scope, 'job_role:') ? (int) substr($scope, 9) : 0;
        $job = JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->find($id);
        if (! $job) {
            throw ValidationException::withMessages(['scope' => __('requirements.unknown_position')]);
        }

        return $job;
    }

    private function scopeKeyOf(Requirement $r): string
    {
        $ids = $r->applicability['job_role_ids'] ?? [];

        return $ids === [] ? 'all' : (count($ids) === 1 ? 'job_role:'.$ids[0] : 'mixed');
    }

    private function peopleOf(string $scope): array
    {
        $job = $this->jobRoleOf($scope);
        $q = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', '!=', 'terminated');
        $job && $q->where('job_role_id', $job->id);
        $people = $q->get(['user_id']);

        return ['total' => $people->count(), 'accountless' => $people->whereNull('user_id')->count()];
    }

    /** @return array<string, array{code: string, label: string}> keyed by « kind:id » */
    private function labels(): array
    {
        $out = [];
        foreach (FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))->get() as $d) {
            $out['field:'.$d->id] = ['code' => $d->code, 'label' => $d->resolvedLabel(\App\Core\Fields\FieldResolverService::requestLocale())];
        }
        foreach (DocumentType::where('scope', DocumentType::SCOPE_COMPANY_USER)->where(fn ($q) => $q->where('is_system', true)->orWhere('company_id', $this->company->id))->get() as $t) {
            $out['document:'.$t->id] = ['code' => $t->code, 'label' => $t->label];
        }

        return $out;
    }

    /** Provenance in business language (no technical identifier). */
    private function origin(array $c): array
    {
        $jobIds = $c['applicability']['job_role_ids'] ?? [];

        $module = $c['source'] === 'module' ? (\App\Core\Modules\ModuleRegistry::definitions()[$c['source_ref']] ?? null)?->name : null;

        return ['kind' => $c['source'], 'scope' => $jobIds === [] ? 'all' : 'position', 'module' => $module, 'version' => $c['source_version'] ?? null, 'customized' => $c['customized'] ?? false];
    }
}
