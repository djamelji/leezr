<?php

namespace App\Core\Fields;

use App\Core\Markets\Market;
use App\Core\Models\User;
use App\Core\Workforce\ContractualIdentityLock;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ADR-795.1 — the SINGLE write path of company-user field values, whatever the screen :
 *   hr (person file / employee file), members (member management), self (own profile),
 *   suggestion (values extracted from a document, applied by a member manager).
 *
 * Per field :
 *  - target : EMPLOYMENT → the Employee record of THIS company, or the member's Membership in this company
 *    when there is no HR record (never the account ; the right follows the record : workforce.manage for
 *    the HR record, members.manage for the membership) ;
 *    PERSON → the account when it exists (recorded as DISCLOSED to this company), else the Employee record.
 *  - employment data : written only with the HR permission (workforce.manage), from any screen ; the
 *    person never writes the company's HR data about themself ; personal data yes.
 *  - sensitive : the hr context needs the sensitive read right (403 otherwise) ; other contexts silently
 *    IGNORE a sensitive field the actor cannot read (the screen only showed a masked value — never an
 *    overwrite with it) and report it.
 *  - established contract (ADR-787) : a protected field (everything but contact details) — first entry
 *    included — changes only with the HR permission (workforce.manage) AND an explicit confirmation ;
 *    the person themself : never. The server decides, a browser flag alone never suffices.
 *  - unchanged value : no write (idempotent) ; type validation identical everywhere ; the person's row
 *    lock is taken FIRST ; every change historised (sensitive = fingerprint).
 */
final class PersonFieldWriter
{
    public const CONTEXT_HR = 'hr';
    public const CONTEXT_MEMBERS = 'members';
    public const CONTEXT_SELF = 'self';
    public const CONTEXT_SUGGESTION = 'suggestion';
    /** ADR-796 : HR validates values PROVIDED BY THE PERSON in an onboarding file (person data → account if any). */
    public const CONTEXT_ONBOARDING = 'onboarding';

    /** Default purpose recorded in the disclosure registry (never a legal basis : that one is DECLARED). */
    private const PURPOSES = ['self' => 'self_service', 'members' => 'hr_record', 'hr' => 'hr_record', 'suggestion' => 'document_extraction', 'onboarding' => 'onboarding'];

    public function __construct(private EmployeeFieldStore $store) {}

    /**
     * @return array{changed: list<string>, ignored: list<string>}
     */
    /**
     * @param  array{purpose?: string, legal_basis?: ?string, notice_version?: ?string, invitation_id?: ?int}  $collection
     *         traceability of the collection (ADR-796 prérequis C) — the legal basis is only what the company DECLARED
     */
    public function write(?Employee $employee, ?User $user, array $input, ?User $actor, string $context, bool $confirmContractual = false, array $collection = []): array
    {
        $company = $this->store->company();
        if ($employee) {
            $this->store->assertOwned($employee);
            $user ??= $employee->user_id ? User::find($employee->user_id) : null;
        } elseif ($user) {
            $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->get()->first();
        }
        $byCode = $this->store->definitions()->keyBy('code');
        $prefix = $context === self::CONTEXT_HR ? 'fields' : 'dynamic_fields'; // clé d'erreur de chaque écran

        // 1. Champs inconnus / non activés : refus explicite (RH) ; ignorés comme auparavant ailleurs.
        $unknown = array_diff(array_keys($input), $byCode->keys()->all());
        if ($unknown && $context === self::CONTEXT_HR) {
            throw ValidationException::withMessages(array_fill_keys(array_map(fn ($c) => "{$prefix}.{$c}", $unknown), __('fields.unknown_field')));
        }
        $input = array_diff_key($input, array_flip($unknown));

        // 2. Validation de type identique pour tous les écrans.
        $rules = [];
        foreach ($input as $code => $v) {
            $rules["{$prefix}.{$code}"] = $this->store->rulesFor($byCode[$code]);
        }
        Validator::make([$prefix => $input], $rules)->validate();

        $canSensitive = $actor && ($actor->hasCompanyPermission($company, 'workforce.sensitive_read') || $actor->hasCompanyPermission($company, 'members.sensitive_read'));
        $isHr = $actor && $actor->hasCompanyPermission($company, 'workforce.manage');

        return DB::transaction(function () use ($employee, $user, $input, $byCode, $actor, $context, $confirmContractual, $canSensitive, $isHr, $company, $prefix, $collection) {
            // Verrou EN PREMIER (avant toute lecture) : écritures concurrentes sur la même personne sérialisées.
            $user
                ? User::query()->where('id', $user->id)->lockForUpdate()->first()
                : Employee::withoutGlobalScopes()->where('id', $employee->id)->lockForUpdate()->first();
            $cells = $this->store->cellsFor($employee, $user, $context === self::CONTEXT_SELF ? $user : null);
            $employeeLocked = $employee && $this->store->contractProtected($employee);
            $accountLocked = $user && ContractualIdentityLock::isLocked($user);
            $dial = Market::where('key', $company->market_key)->value('dial_code') ?? '+33';
            $errors = [];
            $changed = [];
            $ignored = [];

            foreach ($input as $code => $value) {
                $def = $byCode[$code];
                $cell = $cells[$code];
                if (in_array($code, ['phone', 'emergency_contact_phone'], true) && is_string($value) && $value !== '') {
                    $value = PhoneNormalizerService::normalize($value, $dial);
                }
                $sensitive = (bool) ($def->validation_rules['sensitive'] ?? false);
                if ($sensitive && $context !== self::CONTEXT_SELF && ! $canSensitive) {
                    if ($context === self::CONTEXT_HR) {
                        abort(403, __('fields.sensitive_forbidden'));
                    }
                    $ignored[] = $code; // l'écran n'a montré qu'une valeur masquée : jamais réécrite

                    continue;
                }
                if (ContractualIdentityLock::norm($cell['value']) === ContractualIdentityLock::norm($value)) {
                    continue; // identique à ce qui est affiché (y compris une valeur historique) : aucune écriture implicite
                }
                if ($cell['not_disclosed'] && $context !== self::CONTEXT_ONBOARDING) { // en intégration, c'est la personne qui fournit la valeur
                    $errors["{$prefix}.{$code}"] = __('fields.not_disclosed'); // jamais écraser une donnée personnelle non communiquée

                    continue;
                }
                $person = $cell['owner'] === FieldOwnership::PERSON;
                $toAccount = $person && $user !== null;
                if ($context === self::CONTEXT_ONBOARDING && ! $isHr) {
                    abort(403); // seule une personne habilitée RH valide un dossier d'intégration
                }
                if ($toAccount && $context === self::CONTEXT_HR) {
                    $errors["{$prefix}.{$code}"] = __('fields.managed_by_account'); // identité du compte : depuis le compte (L5)

                    continue;
                }
                $membership = (! $person && ! $employee && $user) ? \App\Core\Models\Membership::where('company_id', $company->id)->where('user_id', $user->id)->first() : null;
                if (! $person && ! $employee && ! $membership) {
                    $errors["{$prefix}.{$code}"] = __('fields.requires_hr_record');

                    continue;
                }
                if (! $person && $context === self::CONTEXT_SELF) {
                    $errors["{$prefix}.{$code}"] = __('fields.hr_only');

                    continue;
                }
                // Le droit suit l'enregistrement écrit : fiche RH → workforce.manage ; appartenance → members.manage.
                $allowed = $employee ? $isHr : ($actor && $actor->hasCompanyPermission($company, 'members.manage'));
                if (! $person && ! $allowed) {
                    $errors["{$prefix}.{$code}"] = __('fields.hr_permission_required');

                    continue;
                }
                $protected = ! in_array($code, ContractualIdentityLock::EDITABLE, true) && ($toAccount ? $accountLocked : $employeeLocked);
                if ($protected) {
                    if ($context === self::CONTEXT_SELF) {
                        $errors["{$prefix}.{$code}"] = __('profile.contract_locked');

                        continue;
                    }
                    if (! $isHr) {
                        $errors["{$prefix}.{$code}"] = __('fields.hr_validation_required');

                        continue;
                    }
                    if (! $confirmContractual) {
                        $errors["{$prefix}.{$code}"] = __('fields.contract_confirmation_required');

                        continue;
                    }
                }
                [$type, $id] = $toAccount ? ['user', $user->id] : ($employee ? ['employee', $employee->id] : ['membership', $membership->id]);
                $previous = FieldValue::where('model_type', $type)->where('model_id', $id)->where('field_definition_id', $def->id)->value('value');
                FieldValue::updateOrCreate(['field_definition_id' => $def->id, 'model_type' => $type, 'model_id' => $id], ['value' => $value]);
                if ($toAccount) {
                    DB::table('person_field_disclosures')->insertOrIgnore(['user_id' => $user->id, 'field_definition_id' => $def->id,
                        'company_id' => $company->id, 'source' => $context, 'purpose' => $collection['purpose'] ?? self::PURPOSES[$context] ?? null,
                        'legal_basis' => $collection['legal_basis'] ?? null, 'notice_version' => $collection['notice_version'] ?? null,
                        'onboarding_invitation_id' => $collection['invitation_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->history($def, $type, $id, $previous, $value, $actor, $context);
                $changed[] = $code;
            }
            if ($errors) {
                if (in_array(__('fields.contract_confirmation_required'), $errors, true)) {
                    $errors['confirm_contractual'] = __('fields.contract_confirmation_required'); // signal d'interface (le serveur reste seul juge)
                }
                throw ValidationException::withMessages($errors); // annule toute la transaction
            }

            return ['changed' => $changed, 'ignored' => $ignored];
        });
    }

    /** Explicit adoption of a value written on the ACCOUNT before L5 (employment field) — never deletes it. */
    public function adoptLegacy(Employee $employee, string $code, ?User $actor): bool
    {
        $this->store->assertOwned($employee);
        $def = $this->store->definitions()->firstWhere('code', $code);
        if (! $def || ! $employee->user_id || FieldOwnership::of($def) !== FieldOwnership::EMPLOYMENT) {
            return false;
        }

        return DB::transaction(function () use ($employee, $def, $actor) {
            Employee::withoutGlobalScopes()->where('id', $employee->id)->lockForUpdate()->first();
            if (FieldValue::where('model_type', 'employee')->where('model_id', $employee->id)->where('field_definition_id', $def->id)->exists()) {
                return false; // une valeur de l'entreprise existe déjà : jamais écrasée
            }
            $legacy = FieldValue::whereIn('model_type', ['user', User::class])->where('model_id', $employee->user_id)->where('field_definition_id', $def->id)->first();
            if (! $legacy) {
                return false;
            }
            FieldValue::create(['field_definition_id' => $def->id, 'model_type' => 'employee', 'model_id' => $employee->id, 'value' => $legacy->value]);
            $this->history($def, 'employee', $employee->id, null, $legacy->value, $actor, 'legacy_adoption');

            return true;
        });
    }

    private function history(FieldDefinition $def, string $type, int $id, mixed $old, mixed $new, ?User $actor, string $source): void
    {
        $sensitive = (bool) ($def->validation_rules['sensitive'] ?? false);
        $print = fn ($v) => $v === null ? null : ($sensitive ? 'sha256:'.hash('sha256', json_encode($v)) : $v);
        DB::table('field_value_changes')->insert([
            'company_id' => $this->store->company()->id, 'field_definition_id' => $def->id, 'model_type' => $type, 'model_id' => $id,
            'old_value' => json_encode($print($old)), 'new_value' => json_encode($print($new)), 'masked' => $sensitive,
            'source' => $source === self::CONTEXT_HR ? 'hr_edit' : $source, 'changed_by' => $actor?->id, 'created_at' => now(),
        ]);
    }
}
