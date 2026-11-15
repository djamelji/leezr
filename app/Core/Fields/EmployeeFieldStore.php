<?php

namespace App\Core\Fields;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\ContractualIdentityLock;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * ADR-795 (L5) / ADR-795.1 — CANONICAL resolution of company-user field values for one company, shared
 * by every reader (person file, member profile, self profile, DSN, document variables) ; every write
 * goes through PersonFieldWriter.
 *
 * Ownership (FieldOwnership) :
 *   - EMPLOYMENT field → the Employee record of THIS company (a member WITHOUT HR record : their
 *     Membership in this company — never the account). A value written on the account before
 *     L5 is shown as `legacy_account` / `needs_review`, never moved silently.
 *   - PERSON field → the account when the person has one, otherwise the Employee record. Technical
 *     storage on the account is NOT an authorisation : a company reads an account value only if it was
 *     DISCLOSED to it (person_field_disclosures) — or, for a value older than the disclosure registry,
 *     if the person belongs to this company only (unambiguous). Otherwise : hidden, `not_disclosed`.
 * Status : missing | filled | invalid | needs_review. Presence ≠ business validation ≠ obligation.
 */
final class EmployeeFieldStore
{
    public const STATUS_MISSING = 'missing';
    public const STATUS_FILLED = 'filled';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /** Account values may carry the morph alias or (historical fixtures) the class name. */
    private const USER_TYPES = ['user', User::class];

    private ?Collection $definitions = null;
    private ?array $rules = null;

    public function __construct(private Company $company) {}

    public function company(): Company
    {
        return $this->company;
    }

    /** Definitions ACTIVATED for this company (company-user scope, market), keyed by id. */
    public function definitions(): Collection
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $active = FieldActivation::where('company_id', $this->company->id)->where('enabled', true)->get()->keyBy('field_definition_id');
        $market = $this->company->market_key;

        return $this->definitions = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))
            ->whereIn('id', $active->keys())->get()
            ->filter(fn ($d) => empty($d->validation_rules['applicable_markets']) || in_array($market, $d->validation_rules['applicable_markets'], true))
            ->each(fn ($d) => $d->setAttribute('activation_order', $active[$d->id]->order ?? 0))
            ->sortBy('activation_order')->keyBy('id');
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    /** Person file (HR facet). */
    public function read(Employee $employee, bool $canReadSensitive): array
    {
        $this->assertOwned($employee);
        $ctx = $this->context(collect([$employee]), collect());
        $protected = $this->contractProtected($employee);
        $out = [];
        foreach ($this->definitions() as $def) {
            $cell = $this->cell($def, $employee, $employee->user_id ? (int) $employee->user_id : null, $ctx);
            $sensitive = (bool) ($def->validation_rules['sensitive'] ?? false);
            $hidden = $sensitive && ! $canReadSensitive;
            $out[] = [
                'code' => $def->code, 'label' => $def->resolvedLabel(FieldResolverService::requestLocale()), 'type' => $def->type,
                'options' => $def->options, 'category' => $def->validation_rules['category'] ?? FieldDefinition::CATEGORY_BASE,
                'owner' => FieldOwnership::of($def), 'source' => $cell['source'], 'status' => $cell['status'],
                'value' => $hidden ? null : $cell['value'], 'masked' => $hidden && $cell['value'] !== null,
                'not_disclosed' => $cell['not_disclosed'],
                'sensitive' => $sensitive,
                'editable' => $cell['editable'] && (! $sensitive || $canReadSensitive),
                'managed_by_account' => ! $cell['editable'],
                'contract_protected' => $protected && ! in_array($def->code, ContractualIdentityLock::EDITABLE, true),
                'required' => false, // les obligations restent celles des moteurs existants (aucune bascule)
            ];
        }

        return $out;
    }

    /**
     * Canonical cells of one person in this company, keyed by code (UNMASKED : internal use — callers
     * apply their own sensitive-read rule). $viewer = the person themself → own account values are
     * always visible to them.
     *
     * @return array<string, array{value: mixed, source: ?string, status: string, owner: string, not_disclosed: bool, editable: bool, definition: FieldDefinition}>
     */
    public function cellsFor(?Employee $employee, ?User $user, ?User $viewer = null): array
    {
        if ($employee) {
            $this->assertOwned($employee);
        } elseif ($user) {
            $employee = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('user_id', $user->id)->get()->first();
        }
        $userId = $user?->id ?? ($employee?->user_id ? (int) $employee->user_id : null);
        $ctx = $this->context($employee ? collect([$employee]) : collect(), $userId ? collect([$userId]) : collect());
        $self = $viewer && $userId && (int) $viewer->id === $userId;
        $out = [];
        foreach ($this->definitions() as $def) {
            $out[$def->code] = $this->cell($def, $employee, $userId, $ctx, $self) + ['owner' => FieldOwnership::of($def), 'definition' => $def];
        }

        return $out;
    }

    /**
     * Batch presence for requirements (L1 registry) : [employee_id][field_definition_id] => status.
     * Constant number of queries whatever the number of employees.
     */
    public function presence(iterable $employees, ?array $definitionIds = null): array
    {
        $employees = collect($employees)->filter(fn ($e) => (int) $e->company_id === (int) $this->company->id)->values();
        $ctx = $this->context($employees, collect());
        $defs = $definitionIds === null ? $this->definitions() : $this->definitions()->filter(fn ($d) => in_array($d->id, $definitionIds, false));
        $out = [];
        foreach ($employees as $e) {
            foreach ($defs as $def) {
                $out[$e->id][$def->id] = $this->cell($def, $e, $e->user_id ? (int) $e->user_id : null, $ctx)['status'];
            }
        }

        return $out;
    }

    /**
     * Canonical UNMASKED values for internal producers (DSN, document variables) — never an API.
     * Codes do not need an activation : a declaration reads what the company legitimately holds.
     *
     * @return array<int, array<string, mixed>> [employee_id => [code => value]]
     */
    public function rawValues(iterable $employees, array $codes): array
    {
        $employees = collect($employees)->filter(fn ($e) => (int) $e->company_id === (int) $this->company->id)->values();
        $defs = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))
            ->whereIn('code', $codes)->get()->keyBy('id');
        $ctx = $this->context($employees, collect(), $defs->keys());
        $out = [];
        foreach ($employees as $e) {
            foreach ($defs as $def) {
                $cell = $this->cell($def, $e, $e->user_id ? (int) $e->user_id : null, $ctx);
                $out[$e->id][$def->code] = $cell['not_disclosed'] ? null : $cell['value'];
            }
        }

        return $out;
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** All rows needed for a set of employees / users, loaded once (no N+1). */
    private function context(Collection $employees, Collection $extraUserIds, ?Collection $defIds = null): array
    {
        $defIds ??= $this->definitions()->keys();
        $key = fn ($v) => $v->model_id.':'.$v->field_definition_id;
        $userIds = $employees->pluck('user_id')->filter()->merge($extraUserIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($userIds->isEmpty()) {
            $users = collect();
            $disclosures = collect();
            $companyCount = [];
        } else {
            $users = FieldValue::whereIn('model_type', self::USER_TYPES)->whereIn('model_id', $userIds)->whereIn('field_definition_id', $defIds)->get()->keyBy($key);
        }
        if ($users->isEmpty()) {
            $disclosures = collect(); // aucune valeur sur le compte : rien à autoriser
            $companyCount = [];
        } else {
            $disclosures = DB::table('person_field_disclosures')->whereIn('user_id', $userIds)->whereIn('field_definition_id', $defIds)->get(['user_id', 'field_definition_id', 'company_id'])
                ->groupBy(fn ($d) => $d->user_id.':'.$d->field_definition_id);
            $companyCount = DB::table('memberships')->whereIn('user_id', $userIds)->select('user_id', 'company_id')
                ->union(DB::table('workforce_employees')->whereIn('user_id', $userIds)->select('user_id', 'company_id'))->get()
                ->groupBy('user_id')->map(fn ($rows) => $rows->pluck('company_id')->unique()->count())->all();
        }

        // Données d'emploi portées par l'appartenance à CETTE société (membre sans fiche RH, ou en attente de
        // reprise après création de la fiche — ADR-796 prérequis A : jamais invisibles).
        $memberships = $userIds->isEmpty() ? collect() : \App\Core\Models\Membership::where('company_id', $this->company->id)->whereIn('user_id', $userIds)->pluck('id', 'user_id');
        $membershipValues = $memberships->isEmpty() ? collect() : FieldValue::where('model_type', 'membership')->whereIn('model_id', $memberships->values())->whereIn('field_definition_id', $defIds)->get()
            ->keyBy(fn ($v) => $memberships->search($v->model_id).':'.$v->field_definition_id);

        return [
            'employee' => $employees->isEmpty() ? collect() : FieldValue::where('model_type', 'employee')->whereIn('model_id', $employees->pluck('id'))->whereIn('field_definition_id', $defIds)->get()->keyBy($key),
            'user' => $users, 'disclosures' => $disclosures, 'companyCount' => $companyCount,
            'membership' => $membershipValues, 'memberships' => $memberships,
        ];
    }

    /** @return array{value: mixed, source: ?string, status: string, not_disclosed: bool, editable: bool} */
    private function cell(FieldDefinition $def, ?Employee $e, ?int $userId, array $ctx, bool $self = false): array
    {
        $own = $e ? ($ctx['employee'][$e->id.':'.$def->id] ?? null) : null;
        $acc = $userId ? ($ctx['user'][$userId.':'.$def->id] ?? null) : null;
        $filled = fn ($r) => $r !== null && $r->value !== null && $r->value !== '' && $r->value !== [];
        $owner = FieldOwnership::of($def);

        if ($owner === FieldOwnership::PERSON && $userId) {
            $disclosed = $self || ! $filled($acc) || $this->disclosed($userId, $def->id, $ctx);
            if (! $disclosed) {
                return ['value' => null, 'source' => 'account', 'status' => self::STATUS_NEEDS_REVIEW, 'not_disclosed' => true, 'editable' => false];
            }
            $row = $filled($acc) ? $acc : ($filled($own) ? $own : null);
            $conflict = $filled($acc) && $filled($own) && ContractualIdentityLock::norm($acc->value) !== ContractualIdentityLock::norm($own->value);
            $review = $conflict || (! $filled($acc) && $filled($own)); // saisi avant la création du compte : à rapprocher

            return ['value' => $row?->value, 'source' => $row === null ? null : ($row === $acc ? 'account' : 'employee'),
                'status' => $review ? self::STATUS_NEEDS_REVIEW : $this->statusOf($def, $row), 'not_disclosed' => false, 'editable' => false];
        }
        $mem = $userId ? ($ctx['membership'][$userId.':'.$def->id] ?? null) : null;
        $editable = $e !== null || ($userId && isset($ctx['memberships'][$userId]));
        if ($owner === FieldOwnership::EMPLOYMENT && ! $filled($own) && $filled($mem)) {
            // Avec fiche RH : valeur de l'appartenance encore à reprendre (MembershipFieldTransfer) → à vérifier.
            return ['value' => $mem->value, 'source' => 'membership', 'status' => $e ? self::STATUS_NEEDS_REVIEW : $this->statusOf($def, $mem), 'not_disclosed' => false, 'editable' => $editable];
        }
        if ($owner === FieldOwnership::EMPLOYMENT && ! $filled($own) && $filled($acc)) {
            return ['value' => $acc->value, 'source' => 'legacy_account', 'status' => self::STATUS_NEEDS_REVIEW, 'not_disclosed' => false, 'editable' => $editable];
        }

        return ['value' => $own?->value, 'source' => $own ? 'employee' : null, 'status' => $this->statusOf($def, $own), 'not_disclosed' => false, 'editable' => $editable];
    }

    /** Disclosed to THIS company — or a value older than the registry held by a single-company person. */
    private function disclosed(int $userId, int $defId, array $ctx): bool
    {
        $rows = $ctx['disclosures'][$userId.':'.$defId] ?? collect();
        if ($rows->isNotEmpty()) {
            return $rows->contains(fn ($d) => (int) $d->company_id === (int) $this->company->id); // une demande de retrait est tracée, jamais interprétée ici
        }

        return ($ctx['companyCount'][$userId] ?? 0) <= 1;
    }

    public function statusOf(FieldDefinition $def, ?FieldValue $row): string
    {
        if ($row === null || $row->value === null || $row->value === '' || $row->value === []) {
            return self::STATUS_MISSING;
        }

        return Validator::make(['v' => $row->value], ['v' => $this->rulesFor($def)])->fails() ? self::STATUS_INVALID : self::STATUS_FILLED;
    }

    public function rulesFor(FieldDefinition $def): array
    {
        $this->rules ??= FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $this->company->id, null, $this->company->market_key);
        $rules = $this->rules["dynamic_fields.{$def->code}"] ?? ['nullable'];

        // Présence ≠ obligation : une écriture partielle ou une valeur vide est toujours acceptée ici.
        return array_values(array_map(fn ($r) => $r === 'required' ? 'nullable' : $r, array_filter($rules, fn ($r) => $r !== 'sometimes')));
    }

    public function contractProtected(Employee $e): bool
    {
        return EmploymentContract::withoutGlobalScopes()->where('company_id', $this->company->id)->where('employee_id', $e->id)
            ->whereIn('status', ContractualIdentityLock::ESTABLISHED)->exists();
    }

    public function assertOwned(Employee $e): void
    {
        if ((int) $e->company_id !== (int) $this->company->id) {
            abort(404); // jamais de lecture ni d'écriture d'un salarié d'une autre société
        }
    }

    // ── Compatibilité L5 (API RH) ───────────────────────────────────────────

    public function write(Employee $employee, array $input, ?User $actor, bool $canReadSensitive, bool $confirmContractual = false): array
    {
        $changed = (new PersonFieldWriter($this))->write($employee, null, $input, $actor, PersonFieldWriter::CONTEXT_HR, $confirmContractual);

        return ['changed' => $changed['changed'], 'fields' => $this->read($employee, $canReadSensitive)];
    }

    /** Explicit adoption of a value written on the ACCOUNT before L5 (employment field) — never deletes it. */
    public function adoptLegacy(Employee $employee, string $code, ?User $actor): bool
    {
        return (new PersonFieldWriter($this))->adoptLegacy($employee, $code, $actor);
    }
}
