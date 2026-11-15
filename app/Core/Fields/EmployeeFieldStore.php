<?php

namespace App\Core\Fields;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\ContractualIdentityLock;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ADR-795 (L5) — dynamic company-user fields of an EMPLOYEE, with or without account.
 *
 * Same definitions, activations and `field_values` table as everywhere else (no parallel system) ; the
 * new owner is the Employee record (`model_type = employee`), isolated per company by construction.
 * Ownership (FieldOwnership) :
 *   - EMPLOYMENT field → the Employee value. A value written on the account before L5 is shown as
 *     `legacy_account` / `needs_review`, never moved silently (explicit adoption only).
 *   - PERSON field → the account value when the person has one (unchanged path : member profile) ; the
 *     Employee value otherwise. After an account is created, an Employee value that differs from the
 *     account is reported `needs_review` — nothing is overwritten.
 * Status (presence ≠ business validation) : missing | filled | invalid | needs_review.
 * Sensitive values are returned only with the sensitive read right (never partially).
 * Writes : validated by type, serialised (employee row lock first), historised (sensitive = fingerprint),
 * and a field protected by an ESTABLISHED contract (ADR-787) changes only with an explicit confirmation.
 */
final class EmployeeFieldStore
{
    public const STATUS_MISSING = 'missing';
    public const STATUS_FILLED = 'filled';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    private ?Collection $definitions = null;
    private ?array $rules = null;

    public function __construct(private Company $company) {}

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

    public function read(Employee $employee, bool $canReadSensitive): array
    {
        $this->assertOwned($employee);
        $values = $this->valuesFor(collect([$employee]));
        $protected = $this->contractProtected($employee);
        $out = [];
        foreach ($this->definitions() as $def) {
            $cell = $this->cell($def, $employee, $values);
            $sensitive = (bool) ($def->validation_rules['sensitive'] ?? false);
            $hidden = $sensitive && ! $canReadSensitive;
            $out[] = [
                'code' => $def->code, 'label' => $def->resolvedLabel(FieldResolverService::requestLocale()), 'type' => $def->type,
                'options' => $def->options, 'category' => $def->validation_rules['category'] ?? FieldDefinition::CATEGORY_BASE,
                'owner' => FieldOwnership::of($def), 'source' => $cell['source'], 'status' => $cell['status'],
                'value' => $hidden ? null : $cell['value'], 'masked' => $hidden && $cell['value'] !== null,
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
     * Batch presence for requirements (L1 registry) : [employee_id][field_definition_id] => status.
     * Two queries whatever the number of employees.
     *
     * @param  iterable<Employee>  $employees
     */
    public function presence(iterable $employees, ?array $definitionIds = null): array
    {
        $employees = collect($employees)->filter(fn ($e) => (int) $e->company_id === (int) $this->company->id)->values();
        $values = $this->valuesFor($employees);
        $defs = $definitionIds === null ? $this->definitions() : $this->definitions()->filter(fn ($d) => in_array($d->id, $definitionIds, false));
        $out = [];
        foreach ($employees as $e) {
            foreach ($defs as $def) {
                $out[$e->id][$def->id] = $this->cell($def, $e, $values)['status'];
            }
        }

        return $out;
    }

    // ── Écriture (RH) ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input  code => value (partial ; null clears)
     * @return array{changed: list<string>, fields: array}
     */
    public function write(Employee $employee, array $input, ?User $actor, bool $canReadSensitive, bool $confirmContractual = false): array
    {
        $this->assertOwned($employee);
        $byCode = $this->definitions()->keyBy('code');
        $errors = [];
        foreach (array_keys($input) as $code) {
            $def = $byCode[$code] ?? null;
            if (! $def) {
                $errors["fields.{$code}"] = __('fields.unknown_field');

                continue;
            }
            if (FieldOwnership::of($def) === FieldOwnership::PERSON && $employee->user_id !== null) {
                $errors["fields.{$code}"] = __('fields.managed_by_account');
            }
            if (($def->validation_rules['sensitive'] ?? false) && ! $canReadSensitive) {
                abort(403, __('fields.sensitive_forbidden'));
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $rules = [];
        foreach ($input as $code => $v) {
            $rules["fields.{$code}"] = $this->rulesFor($byCode[$code]);
        }
        Validator::make(['fields' => $input], $rules)->validate();

        $changed = DB::transaction(function () use ($employee, $input, $byCode, $actor, $confirmContractual) {
            Employee::withoutGlobalScopes()->where('id', $employee->id)->lockForUpdate()->first(); // écritures concurrentes sérialisées
            $current = FieldValue::where('model_type', 'employee')->where('model_id', $employee->id)
                ->whereIn('field_definition_id', array_map(fn ($c) => $byCode[$c]->id, array_keys($input)))->get()->keyBy('field_definition_id');
            $protected = $this->contractProtected($employee);
            $dial = Market::where('key', $this->company->market_key)->value('dial_code') ?? '+33';
            $changed = [];
            $blocked = [];
            foreach ($input as $code => $value) {
                $def = $byCode[$code];
                if (in_array($code, ['phone', 'emergency_contact_phone'], true) && is_string($value) && $value !== '') {
                    $value = PhoneNormalizerService::normalize($value, $dial);
                }
                $row = $current[$def->id] ?? null;
                if ($row && ContractualIdentityLock::norm($row->value) === ContractualIdentityLock::norm($value)) {
                    continue; // idempotent
                }
                if ($protected && ! $confirmContractual && ! in_array($code, ContractualIdentityLock::EDITABLE, true)) {
                    $blocked["fields.{$code}"] = __('fields.contract_confirmation_required');

                    continue;
                }
                FieldValue::updateOrCreate(['field_definition_id' => $def->id, 'model_type' => 'employee', 'model_id' => $employee->id], ['value' => $value]);
                $this->history($employee, $def, $row?->value, $value, $actor, 'hr_edit');
                $changed[] = $code;
            }
            if ($blocked) {
                throw ValidationException::withMessages($blocked); // annule toute la transaction
            }

            return $changed;
        });

        return ['changed' => $changed, 'fields' => $this->read($employee, $canReadSensitive)];
    }

    /** Explicit adoption of a value written on the ACCOUNT before L5 (employment field) — never deletes it. */
    public function adoptLegacy(Employee $employee, string $code, ?User $actor): bool
    {
        $this->assertOwned($employee);
        $def = $this->definitions()->firstWhere('code', $code);
        if (! $def || ! $employee->user_id || FieldOwnership::of($def) !== FieldOwnership::EMPLOYMENT) {
            return false;
        }

        return DB::transaction(function () use ($employee, $def, $actor) {
            Employee::withoutGlobalScopes()->where('id', $employee->id)->lockForUpdate()->first();
            if (FieldValue::where('model_type', 'employee')->where('model_id', $employee->id)->where('field_definition_id', $def->id)->exists()) {
                return false; // une valeur de l'entreprise existe déjà : jamais écrasée
            }
            $legacy = FieldValue::where('model_type', 'user')->where('model_id', $employee->user_id)->where('field_definition_id', $def->id)->first();
            if (! $legacy) {
                return false;
            }
            FieldValue::create(['field_definition_id' => $def->id, 'model_type' => 'employee', 'model_id' => $employee->id, 'value' => $legacy->value]);
            $this->history($employee, $def, null, $legacy->value, $actor, 'legacy_adoption');

            return true;
        });
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** @return array{employee: Collection, user: Collection} values keyed "model_id:definition_id" */
    private function valuesFor(Collection $employees): array
    {
        $defIds = $this->definitions()->keys();
        $key = fn ($v) => $v->model_id.':'.$v->field_definition_id;
        $userIds = $employees->pluck('user_id')->filter()->unique();

        return [
            'employee' => $employees->isEmpty() ? collect() : FieldValue::where('model_type', 'employee')->whereIn('model_id', $employees->pluck('id'))->whereIn('field_definition_id', $defIds)->get()->keyBy($key),
            'user' => $userIds->isEmpty() ? collect() : FieldValue::where('model_type', 'user')->whereIn('model_id', $userIds)->whereIn('field_definition_id', $defIds)->get()->keyBy($key),
        ];
    }

    /** @return array{value: mixed, source: ?string, status: string, editable: bool} */
    private function cell(FieldDefinition $def, Employee $e, array $values): array
    {
        $own = $values['employee'][$e->id.':'.$def->id] ?? null;
        $acc = $e->user_id ? ($values['user'][$e->user_id.':'.$def->id] ?? null) : null;
        $filled = fn ($r) => $r !== null && $r->value !== null && $r->value !== '' && $r->value !== [];

        if (FieldOwnership::of($def) === FieldOwnership::PERSON && $e->user_id) {
            $row = $filled($acc) ? $acc : ($filled($own) ? $own : null);
            $conflict = $filled($acc) && $filled($own) && ContractualIdentityLock::norm($acc->value) !== ContractualIdentityLock::norm($own->value);
            $review = $conflict || (! $filled($acc) && $filled($own)); // saisi avant la création du compte : à rapprocher

            return ['value' => $row?->value, 'source' => $row === $acc && $row ? 'account' : ($row ? 'employee' : null),
                'status' => $review ? self::STATUS_NEEDS_REVIEW : $this->statusOf($def, $row), 'editable' => false];
        }
        if (! $filled($own) && $filled($acc) && FieldOwnership::of($def) === FieldOwnership::EMPLOYMENT) {
            return ['value' => $acc->value, 'source' => 'legacy_account', 'status' => self::STATUS_NEEDS_REVIEW, 'editable' => true];
        }

        return ['value' => $own?->value, 'source' => $own ? 'employee' : null, 'status' => $this->statusOf($def, $own), 'editable' => true];
    }

    private function statusOf(FieldDefinition $def, ?FieldValue $row): string
    {
        if ($row === null || $row->value === null || $row->value === '' || $row->value === []) {
            return self::STATUS_MISSING;
        }

        return Validator::make(['v' => $row->value], ['v' => $this->rulesFor($def)])->fails() ? self::STATUS_INVALID : self::STATUS_FILLED;
    }

    private function rulesFor(FieldDefinition $def): array
    {
        $this->rules ??= FieldValidationService::rules(FieldDefinition::SCOPE_COMPANY_USER, $this->company->id, null, $this->company->market_key);
        $rules = $this->rules["dynamic_fields.{$def->code}"] ?? ['nullable'];

        // Présence ≠ obligation : une écriture partielle ou une valeur vide est toujours acceptée ici.
        return array_values(array_map(fn ($r) => $r === 'required' ? 'nullable' : $r, array_filter($rules, fn ($r) => $r !== 'sometimes')));
    }

    private function contractProtected(Employee $e): bool
    {
        return EmploymentContract::withoutGlobalScopes()->where('company_id', $this->company->id)->where('employee_id', $e->id)
            ->whereIn('status', ContractualIdentityLock::ESTABLISHED)->exists();
    }

    private function history(Employee $e, FieldDefinition $def, mixed $old, mixed $new, ?User $actor, string $source): void
    {
        $sensitive = (bool) ($def->validation_rules['sensitive'] ?? false);
        $print = fn ($v) => $v === null ? null : ($sensitive ? 'sha256:'.hash('sha256', json_encode($v)) : $v);
        DB::table('field_value_changes')->insert([
            'company_id' => $this->company->id, 'field_definition_id' => $def->id, 'model_type' => 'employee', 'model_id' => $e->id,
            'old_value' => json_encode($print($old)), 'new_value' => json_encode($print($new)), 'masked' => $sensitive,
            'source' => $source, 'changed_by' => $actor?->id, 'created_at' => now(),
        ]);
    }

    private function assertOwned(Employee $e): void
    {
        if ((int) $e->company_id !== (int) $this->company->id) {
            abort(404); // jamais de lecture ni d'écriture d'un salarié d'une autre société
        }
    }
}
