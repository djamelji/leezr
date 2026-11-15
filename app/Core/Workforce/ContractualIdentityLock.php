<?php

namespace App\Core\Workforce;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\User;

/**
 * ADR-787 — once a contract is ESTABLISHED for a person, the data it relies on is no longer
 * self-editable : the employee asks the company (HR keeps its own editing paths, unchanged).
 *
 *  - Established = a contract `active`, `suspended` or `terminated` (a `draft` or a `cancelled`
 *    contract never locks anything). Generated / signed documents are never modified.
 *  - Still editable : contact details (phone, addresses, emergency contacts) and the account itself
 *    (login e-mail, password, avatar, preferences).
 *  - Scope : company-user field values are stored PER USER (shared by all their companies, see
 *    FieldValue model_type = user), and so is the name. A protected value is therefore the same in
 *    every company : the lock applies as soon as ONE of the person's companies holds an established
 *    contract — locking it for one company only would let another company's screen change it.
 */
final class ContractualIdentityLock
{
    public const ESTABLISHED = [EmploymentContract::STATUS_ACTIVE, EmploymentContract::STATUS_SUSPENDED, EmploymentContract::STATUS_TERMINATED];

    /** Coordinates the person keeps editing after the contract. */
    public const EDITABLE = [
        'phone', 'address', 'emergency_contact_name', 'emergency_contact_phone',
        'personal_address_street', 'personal_address_postal_code', 'personal_address_city', 'personal_address_country_code',
    ];

    public const BASE_LOCKED = ['first_name', 'last_name'];

    public static function isLocked(User $user): bool
    {
        return EmploymentContract::withoutGlobalScopes()
            ->whereIn('status', self::ESTABLISHED)
            ->whereIn('employee_id', Employee::withoutGlobalScopes()->where('user_id', $user->id)->select('id'))
            ->exists();
    }

    /** @return array{locked: bool, fields: string[]} fields = base + dynamic codes read-only for the person */
    public static function describe(User $user): array
    {
        if (! self::isLocked($user)) {
            return ['locked' => false, 'fields' => []];
        }
        $dynamic = FieldDefinition::whereNull('company_id')->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->whereNotIn('code', self::EDITABLE)->pluck('code')->all();

        return ['locked' => true, 'fields' => array_values(array_merge(self::BASE_LOCKED, $dynamic))];
    }

    /**
     * Fields the request would CHANGE although they are locked (re-sending the current value is fine :
     * the profile form posts every field).
     *
     * @return array<string, string> request key => message
     */
    public static function violations(User $user, array $validated): array
    {
        $lock = self::describe($user);
        if (! $lock['locked']) {
            return [];
        }
        $message = __('profile.contract_locked');
        $out = [];
        foreach (self::BASE_LOCKED as $field) {
            if (array_key_exists($field, $validated) && self::norm($validated[$field]) !== self::norm($user->{$field})) {
                $out[$field] = $message;
            }
        }
        $dynamic = array_intersect_key($validated['dynamic_fields'] ?? [], array_flip($lock['fields']));
        if ($dynamic) {
            $current = FieldValue::where('model_type', 'user')->where('model_id', $user->id)
                ->whereHas('definition', fn ($q) => $q->whereIn('code', array_keys($dynamic)))
                ->with('definition:id,code')->get()->pluck('value', 'definition.code');
            foreach ($dynamic as $code => $value) {
                if (self::norm($value) !== self::norm($current[$code] ?? null)) {
                    $out["dynamic_fields.{$code}"] = $message;
                }
            }
        }

        return $out;
    }

    public static function norm($v): string
    {
        if (is_bool($v) || in_array($v, ['true', 'false'], true)) {
            return ($v === true || $v === 'true') ? '1' : '';
        }

        return is_array($v) ? json_encode($v) : trim((string) ($v ?? ''));
    }
}
