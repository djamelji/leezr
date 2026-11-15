<?php

namespace App\Core\Modules\Contributions;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentConfidentiality;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Requirements\Requirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-794 (L4) — what a module brings to ONE company, and the company's explicit adoption (C, prepared).
 *
 * PARALLEL MODE : adoption only writes company COPIES in the L1 registry (source module) and a decision
 * journal ; the operational resolution (ADR-790), forms, DMS requests, compliance, reminders, contracts
 * and payroll are untouched. An adopted copy is automatically suspended by the L1 resolver while the
 * module is inactive (R1) and comes back on reactivation — nothing is deleted, nothing is duplicated.
 *
 * Lifecycle (never inferred from the historical `is_enabled_for_company` flag alone) :
 *   active | available (entitled, not activated) | deactivated (entitled, was adopted before) |
 *   suspended (addon deactivated) | not_authorized | platform_disabled | ambiguous (sources disagree).
 * Only `active` allows an adoption.
 */
final class CompanyModuleContributions
{
    public const ADOPTABLE = ['optional', 'proposed_required', 'requires_confirmation'];

    private ?array $ctx = null;

    /** @var array<string, string> */
    private array $states = [];

    public function __construct(private Company $company, private ?User $actor = null) {}

    public static function lifecycle(Company $company, string $key): string
    {
        $manifest = ModuleRegistry::definitions()[$key] ?? null;
        if (! $manifest || $manifest->scope !== 'company') {
            return 'not_authorized';
        }
        $pm = PlatformModule::where('key', $key)->first();
        if (! $pm || ! $pm->is_enabled_globally) {
            return 'platform_disabled';
        }
        if ($manifest->type === 'core') {
            return 'active';
        }
        $entitlement = EntitlementResolver::check($company, $key, $pm);
        $reason = CompanyModuleActivationReason::where('company_id', $company->id)->where('module_key', $key)->exists();
        $flag = CompanyModule::withoutGlobalScopes()->where('company_id', $company->id)->where('module_key', $key)->value('is_enabled_for_company');
        if (! $entitlement['entitled']) {
            return match (true) {
                $entitlement['reason'] === 'addon_inactive' => 'suspended',
                $reason => 'ambiguous', // activé sans droit réel
                default => 'not_authorized',
            };
        }
        if ($reason) {
            return 'active';
        }
        if ($flag) {
            return 'ambiguous'; // drapeau historique sans raison d'activation : jamais d'obligation déduite
        }
        $adoptedBefore = ModuleContributionDecision::where('company_id', $company->id)->where('module_key', $key)->exists();

        return $adoptedBefore ? 'deactivated' : 'available';
    }

    // ── Lecture (aucune écriture) ───────────────────────────────────────────

    /** Every module declaring contributions, explained for this company. */
    public function overview(): array
    {
        $ctx = $this->context();
        $out = [];
        foreach ($ctx['contributions']->groupBy('module_key') as $module => $items) {
            $manifest = ModuleRegistry::definitions()[$module] ?? null;
            if (! $manifest) {
                continue;
            }
            $state = $this->state($module);
            $proposals = $items->where('kind', 'requirement')->map(fn ($c) => $this->proposal($c, $state))->filter()->values()->all();
            $out[] = [
                'module_key' => $module, 'name' => $manifest->name, 'state' => $state, 'adoptable' => $state === 'active',
                'fields' => $items->where('kind', 'field')->map(fn ($c) => $this->definition($c))->filter()->values()->all(),
                'documents' => $items->where('kind', 'document')->map(fn ($c) => $this->definition($c))->filter()->values()->all(),
                'proposals' => $proposals,
                'suspended_now' => $state === 'active' ? [] : $this->impact($module),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** What a deactivation keeps and suspends (projection, read-only : the L1 resolver applies it alone). */
    public function impact(string $module): array
    {
        $ctx = $this->context();
        $out = [];
        foreach ($ctx['rows'] as $row) {
            $template = $ctx['templatesById'][$row->derived_from_id] ?? null;
            if (! $template || $template->source_ref !== $module || $template->source !== 'module') {
                continue;
            }
            $shared = $ctx['rows']->contains(fn ($o) => $o->id !== $row->id && $o->targetKey() === $row->targetKey()
                && ($o->applicability['job_role_ids'] ?? []) == ($row->applicability['job_role_ids'] ?? [])
                && ($o->source === 'company' || ($o->source === 'module' && $o->source_ref !== $module && $this->state($o->source_ref) === 'active')));
            $outcome = match (true) {
                $row->source === 'company' => 'kept_company_decision',
                $shared => 'kept_other_source',
                default => 'suspended',
            };
            $out[] = ['label' => $this->labelOf($row->target_kind, (int) ($row->field_definition_id ?? $row->document_type_id)), 'kind' => $row->target_kind,
                'scope' => $this->scopeLabel($row->applicability), 'outcome' => $outcome];
        }

        return $out;
    }

    /** Preview of an adoption : the same code inside a transaction always rolled back. */
    public function preview(string $module, array $decisions, array $can): array
    {
        DB::beginTransaction();
        try {
            $result = $this->decide($module, $decisions, $can, false);
        } finally {
            DB::rollBack(); // aperçu : rien n'est jamais écrit
            $this->ctx = null;
        }

        return $result;
    }

    public function commit(string $module, array $decisions, array $can): array
    {
        $result = DB::transaction(fn () => $this->decide($module, $decisions, $can, true));
        $this->ctx = null;

        return $result;
    }

    // ── Décisions ───────────────────────────────────────────────────────────

    /** @param list<array{ref: string, decision: string}> $decisions  adopt | decline | keep | replace */
    private function decide(string $module, array $decisions, array $can, bool $audit): array
    {
        // Verrou EN PREMIER : sous MySQL (REPEATABLE READ) la première lecture fige l'instantané ; verrouiller
        // avant toute lecture garantit que chaque décision voit celles déjà validées (idempotence concurrente).
        Company::query()->where('id', $this->company->id)->lockForUpdate()->first();
        $this->ctx = null;
        $this->states = [];
        if (self::lifecycle($this->company, $module) !== 'active') {
            throw ValidationException::withMessages(['module' => __('requirements.module_not_active')]);
        }
        $ctx = $this->context();
        $byRef = $ctx['contributions']->where('module_key', $module)->where('kind', 'requirement')->keyBy('ref');
        $done = ['adopted' => [], 'updated' => [], 'declined' => [], 'kept' => [], 'replaced' => [], 'skipped' => []];

        foreach ($decisions as $d) {
            $c = $byRef[$d['ref'] ?? ''] ?? null;
            $p = $c ? $this->proposal($c, 'active') : null;
            if (! $p) {
                throw ValidationException::withMessages(['decisions' => __('requirements.unknown_proposal')]);
            }
            abort_unless($can[$p['kind'] === 'field' ? 'fields' : 'documents'] ?? false, 403, __('requirements.forbidden_section'));
            $label = ['ref' => $p['ref'], 'label' => $p['label']];
            $template = $ctx['templatesByRef'][$module.'|'.$p['ref']] ?? null;
            $decision = $d['decision'] ?? '';

            if ($decision === 'decline') {
                $this->journal($module, $p, 'declined', null);
                $done['declined'][] = $label;

                continue;
            }
            if ($decision === 'keep' && $p['customized']) {
                $this->journal($module, $p, 'kept_customization', null);
                $done['kept'][] = $label;

                continue;
            }
            $replace = $decision === 'replace' && $p['customized'] && $p['status'] === 'requires_confirmation';
            $adopt = $decision === 'adopt' && ! $p['customized'] && in_array($p['status'], self::ADOPTABLE, true);
            if (! $template || (! $adopt && ! $replace)) {
                $done['skipped'][] = $label + ['status' => $p['status']];

                continue;
            }
            $previous = $ctx['rows']->filter(fn ($r) => isset($ctx['templatesById'][$r->derived_from_id]) && $this->refOf($ctx['templatesById'][$r->derived_from_id]) === $p['ref']
                && $ctx['templatesById'][$r->derived_from_id]->source_ref === $module);
            foreach ($previous as $r) {
                $r->forceFill(['status' => Requirement::STATUS_SUPERSEDED, 'effective_until' => now(), 'reason' => $replace ? 'replaced_by_module_version' : 'module_update'])->save();
            }
            $created = null;
            foreach ($this->positionsOf($template) as $jobRoleId) {
                $applicability = array_filter(['job_role_ids' => $jobRoleId ? [$jobRoleId] : null, 'contract_types' => $template->applicability['contract_types'] ?? null]);
                $column = $template->target_kind === 'field' ? 'field_definition_id' : 'document_type_id';
                $created = Requirement::create([
                    'company_id' => $this->company->id, 'target_kind' => $template->target_kind, $column => $template->{$column}, 'level' => $template->level,
                    'stage' => $template->stage, 'applicability' => $applicability ?: null, 'source' => 'module', 'source_ref' => $module,
                    'source_version' => $template->source_version, 'derived_from_id' => $template->id, 'status' => Requirement::STATUS_ACTIVE,
                    'effective_from' => now(), 'created_by' => $this->actor?->id, 'reason' => 'proposal:'.$p['ref'],
                ]);
            }
            $kind = $replace ? 'replaced_customization' : ($previous->isNotEmpty() ? 'updated' : 'adopted');
            $this->journal($module, $p, $kind, $created?->id);
            $done[['adopted' => 'adopted', 'updated' => 'updated', 'replaced_customization' => 'replaced'][$kind]][] = $label;
        }

        if ($audit && array_sum(array_map('count', array_diff_key($done, ['skipped' => 1])))) {
            app(AuditLogger::class)->logCompany($this->company->id, 'module_contributions.decided', 'module', $module, [
                'actorId' => $this->actor?->id, 'actorType' => 'user',
                'metadata' => array_map('count', $done) + ['parallel_mode' => true],
            ]);
        }

        return ['changes' => $done, 'people' => $this->peopleConcerned($module, $decisions)];
    }

    private function journal(string $module, array $p, string $decision, ?int $requirementId): void
    {
        ModuleContributionDecision::create(['company_id' => $this->company->id, 'module_key' => $module, 'ref' => $p['ref'], 'version' => $p['version'],
            'decision' => $decision, 'requirement_id' => $requirementId, 'decided_by' => $this->actor?->id]);
    }

    // ── Classification ──────────────────────────────────────────────────────

    private function definition(ModuleContribution $c): ?array
    {
        $ctx = $this->context();
        $id = (int) ($c->field_definition_id ?? $c->document_type_id);
        $isField = $c->kind === 'field';
        $def = $isField ? ($ctx['fields'][$id] ?? null) : ($ctx['documents'][$id] ?? null);
        if ($c->status === ModuleContribution::STATUS_COLLISION || ! $def) {
            return ['label' => $c->payload['definition']['translations'][$this->locale()] ?? null, 'code' => null, 'status' => 'conflict', 'sensitive' => false, 'shared_with' => []];
        }
        $configured = $ctx['rows']->contains(fn ($r) => $r->{$isField ? 'field_definition_id' : 'document_type_id'} === $id);
        $inUse = $isField ? isset($ctx['fieldActivations'][$id]) : isset($ctx['documentActivations'][$id]);
        $shared = $ctx['contributions']->filter(fn ($o) => $o->module_key !== $c->module_key && $o->kind === $c->kind && (int) ($o->field_definition_id ?? $o->document_type_id) === $id)
            ->map(fn ($o) => ModuleRegistry::definitions()[$o->module_key]?->name ?? null)->filter()->values()->all();

        return [
            'label' => $this->labelOf($c->kind, $id),
            'code' => $def->code, // clé de traduction côté interface uniquement (jamais affichée)
            'status' => $configured ? 'already_configured' : ($inUse ? 'already_available' : 'new'),
            'sensitive' => $isField ? (bool) ($def->validation_rules['sensitive'] ?? false) : DocumentConfidentiality::forTypeCode($def->code) === DocumentConfidentiality::HIGHLY_CONFIDENTIAL,
            'shared_with' => $shared, // même définition canonique, plusieurs provenances
        ];
    }

    private function proposal(ModuleContribution $c, string $state): ?array
    {
        $ctx = $this->context();
        if ($c->status === ModuleContribution::STATUS_WITHDRAWN) {
            return null;
        }
        $template = $ctx['templatesByRef'][$c->module_key.'|'.$c->ref] ?? null;
        $p = $c->payload;
        $kind = $p['target_kind'];
        $base = ['ref' => $c->ref, 'kind' => $kind, 'version' => $c->version, 'level' => $p['level'], 'stage' => $p['stage'] ?? null,
            'reason' => $p['reason'][$this->locale()] ?? $p['reason']['en'] ?? null, 'customized' => false, 'previous_decision' => null];
        if ($c->status === ModuleContribution::STATUS_COLLISION || ! $template) {
            return $base + ['label' => null, 'code' => null, 'scope' => null, 'status' => 'conflict', 'sensitive' => false, 'not_applicable_reason' => null, 'update' => null];
        }
        $id = (int) ($template->field_definition_id ?? $template->document_type_id);
        $base += ['label' => $this->labelOf($kind, $id), 'code' => $kind === 'field' ? ($ctx['fields'][$id] ?? null)?->code : ($ctx['documents'][$id] ?? null)?->code, 'scope' => $this->scopeLabel($template->applicability), 'sensitive' => $this->isSensitive($kind, $id)];
        $mine = $ctx['rows']->filter(fn ($r) => isset($ctx['templatesById'][$r->derived_from_id])
            && $ctx['templatesById'][$r->derived_from_id]->source_ref === $c->module_key && $this->refOf($ctx['templatesById'][$r->derived_from_id]) === $c->ref);
        $custom = $mine->first(fn ($r) => $r->source === 'company');
        $copy = $mine->first(fn ($r) => $r->source === 'module');
        $waived = $ctx['rows']->contains(fn ($r) => $r->targetKey() === $template->targetKey() && $r->effect === Requirement::EFFECT_WAIVE);
        $own = $ctx['rows']->contains(fn ($r) => $r->targetKey() === $template->targetKey() && $r->source === 'company' && $r->effect !== Requirement::EFFECT_WAIVE && $r->derived_from_id === null);
        $decision = $ctx['decisions'][$c->module_key.'|'.$c->ref] ?? null;
        $keys = $template->applicability['job_role_keys'] ?? [];
        $origin = $custom ?? $copy;
        $originVersion = $origin ? (int) ($ctx['templatesById'][$origin->derived_from_id]->source_version ?? 0) : null;
        $keptThisVersion = $decision && $decision->decision === 'kept_customization' && $decision->version >= (int) $template->source_version;

        $status = match (true) {
            $state !== 'active' => 'not_applicable',
            $keys !== [] && array_intersect($keys, $ctx['jobKeys']) === [] => 'not_applicable',
            $waived => 'conflict',
            $origin && $originVersion < (int) $template->source_version && ! $keptThisVersion => 'requires_confirmation',
            $origin !== null || $own => 'already_configured',
            default => in_array($p['level'], ['default', 'configurable_required'], true) ? 'proposed_required' : 'optional',
        };

        return array_merge($base, [
            'status' => $status,
            'customized' => $custom !== null,
            'previous_decision' => $decision?->decision,
            'not_applicable_reason' => $state !== 'active' ? 'module_'.$state : ($status === 'not_applicable' ? 'position_missing' : null),
            'update' => $status === 'requires_confirmation' ? ['from' => $originVersion, 'to' => (int) $template->source_version, 'level_now' => $origin->level, 'level_proposed' => $template->level] : null,
        ]);
    }

    private function state(string $module): string
    {
        return $this->states[$module] ??= self::lifecycle($this->company, $module);
    }

    // ── Contexte chargé une fois (pas de N+1) ───────────────────────────────

    private function context(): array
    {
        if ($this->ctx !== null) {
            return $this->ctx;
        }
        $contributions = ModuleContribution::whereIn('status', [ModuleContribution::STATUS_ACTIVE, ModuleContribution::STATUS_COLLISION])->get();
        $rows = Requirement::where('company_id', $this->company->id)->where('status', Requirement::STATUS_ACTIVE)->get();
        $activeTemplates = Requirement::whereNull('company_id')->where('is_template', true)->where('status', Requirement::STATUS_ACTIVE)->get();
        $history = Requirement::whereIn('id', $rows->pluck('derived_from_id')->filter()->unique()->diff($activeTemplates->pluck('id')))->get();
        $templatesById = $activeTemplates->merge($history)->keyBy('id');
        $fieldIds = $contributions->pluck('field_definition_id')->merge($rows->pluck('field_definition_id'))->filter()->unique();
        $docIds = $contributions->pluck('document_type_id')->merge($rows->pluck('document_type_id'))->filter()->unique();

        return $this->ctx = [
            'contributions' => $contributions,
            'rows' => $rows,
            'templatesById' => $templatesById,
            'templatesByRef' => $activeTemplates->keyBy(fn ($t) => $t->source_ref.'|'.$this->refOf($t)),
            'fields' => FieldDefinition::whereIn('id', $fieldIds)->get()->keyBy('id'),
            'documents' => DocumentType::whereIn('id', $docIds)->get()->keyBy('id'),
            'fieldActivations' => FieldActivation::where('company_id', $this->company->id)->where('enabled', true)->whereIn('field_definition_id', $fieldIds)->pluck('id', 'field_definition_id')->all(),
            'documentActivations' => DocumentTypeActivation::where('company_id', $this->company->id)->where('enabled', true)->whereIn('document_type_id', $docIds)->pluck('id', 'document_type_id')->all(),
            'decisions' => ModuleContributionDecision::where('company_id', $this->company->id)->orderBy('id')->get()->keyBy(fn ($d) => $d->module_key.'|'.$d->ref),
            'jobs' => $jobs = JobRole::withoutGlobalScopes()->where('company_id', $this->company->id)->get(['id', 'title', 'preset_item_key']),
            'jobKeys' => $jobs->pluck('preset_item_key')->filter()->values()->all(),
        ];
    }

    private function refOf(Requirement $template): ?string
    {
        return str_starts_with((string) $template->reason, 'proposal:') ? substr($template->reason, 9) : null;
    }

    /** @return list<?int> one copy per matching position (L2 edits per position), or [null] for everyone */
    private function positionsOf(Requirement $template): array
    {
        $keys = $template->applicability['job_role_keys'] ?? [];
        if ($keys === []) {
            return [null];
        }

        return $this->context()['jobs']->whereIn('preset_item_key', $keys)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function peopleConcerned(string $module, array $decisions): array
    {
        $ctx = $this->context();
        $employees = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', '!=', 'terminated')->get(['id', 'user_id', 'job_role_id']);
        $out = [];
        foreach ($decisions as $d) {
            $template = $ctx['templatesByRef'][$module.'|'.($d['ref'] ?? '')] ?? null;
            if (! $template) {
                continue;
            }
            $positions = array_filter($this->positionsOf($template));
            $people = $positions === [] && ($template->applicability['job_role_keys'] ?? []) === [] ? $employees : $employees->whereIn('job_role_id', $positions);
            $out[$d['ref']] = ['total' => $people->count(), 'accountless' => $people->whereNull('user_id')->count()];
        }

        return $out;
    }

    private function labelOf(string $kind, int $id): string
    {
        $ctx = $this->context();
        if ($kind === 'field') {
            return ($ctx['fields'][$id] ?? null)?->resolvedLabel($this->locale()) ?? '—';
        }
        $type = $ctx['documents'][$id] ?? null;

        return $type ? ($type->validation_rules['translations'][$this->locale()] ?? $type->label) : '—';
    }

    private function scopeLabel(?array $applicability): array
    {
        $keys = $applicability['job_role_keys'] ?? [];
        $ids = $applicability['job_role_ids'] ?? [];
        $jobs = $this->context()['jobs'];
        $titles = $jobs->filter(fn ($j) => in_array($j->preset_item_key, $keys, true) || in_array($j->id, $ids, false))->pluck('title')->values()->all();

        return ['everyone' => $keys === [] && $ids === [], 'positions' => $titles, 'contract_types' => $applicability['contract_types'] ?? []];
    }

    private function isSensitive(string $kind, int $id): bool
    {
        $ctx = $this->context();

        return $kind === 'field'
            ? (bool) (($ctx['fields'][$id] ?? null)?->validation_rules['sensitive'] ?? false)
            : DocumentConfidentiality::forTypeCode(($ctx['documents'][$id] ?? null)?->code) === DocumentConfidentiality::HIGHLY_CONFIDENTIAL;
    }

    private function locale(): string
    {
        return FieldResolverService::requestLocale() ?? 'fr';
    }
}
