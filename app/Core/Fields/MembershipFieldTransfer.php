<?php

namespace App\Core\Fields;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\ContractualIdentityLock;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\DB;

/**
 * ADR-796 (prérequis A) — company data held on a MEMBERSHIP (member without HR record, ADR-795.1) taken over
 * by the HR record once it exists. Deterministic (same input → same result), idempotent, limited to the
 * company, never overwrites, never deletes the membership value, respects established-contract
 * protection, historised. Outcomes per field : transferred | already_same | conflict | contract_protected.
 */
final class MembershipFieldTransfer
{
    /** @return array<string, string> code => outcome */
    public static function apply(Company $company, Employee $employee, ?User $actor, bool $dryRun = false): array
    {
        if ((int) $employee->company_id !== (int) $company->id || ! $employee->user_id) {
            return [];
        }
        $run = function () use ($company, $employee, $actor) {
            Employee::withoutGlobalScopes()->where('id', $employee->id)->lockForUpdate()->first();
            $membership = Membership::where('company_id', $company->id)->where('user_id', $employee->user_id)->first();
            if (! $membership) {
                return [];
            }
            $defs = (new EmployeeFieldStore($company))->definitions();
            $memValues = FieldValue::where('model_type', 'membership')->where('model_id', $membership->id)->whereIn('field_definition_id', $defs->keys())->orderBy('field_definition_id')->get();
            $own = FieldValue::where('model_type', 'employee')->where('model_id', $employee->id)->whereIn('field_definition_id', $memValues->pluck('field_definition_id'))->get()->keyBy('field_definition_id');
            $protected = (new EmployeeFieldStore($company))->contractProtected($employee);
            $out = [];
            foreach ($memValues as $mv) {
                $def = $defs[$mv->field_definition_id];
                if (FieldOwnership::of($def) !== FieldOwnership::EMPLOYMENT || $mv->value === null || $mv->value === '') {
                    continue;
                }
                $current = $own[$def->id] ?? null;
                if ($current && $current->value !== null && $current->value !== '') {
                    $out[$def->code] = ContractualIdentityLock::norm($current->value) === ContractualIdentityLock::norm($mv->value) ? 'already_same' : 'conflict';

                    continue;
                }
                if ($protected && ! in_array($def->code, ContractualIdentityLock::EDITABLE, true)) {
                    $out[$def->code] = 'contract_protected'; // une donnée protégée par contrat ne change que par décision RH

                    continue;
                }
                FieldValue::updateOrCreate(['field_definition_id' => $def->id, 'model_type' => 'employee', 'model_id' => $employee->id], ['value' => $mv->value]);
                DB::table('field_value_changes')->insert(['company_id' => $company->id, 'field_definition_id' => $def->id, 'model_type' => 'employee',
                    'model_id' => $employee->id, 'old_value' => json_encode(null), 'new_value' => json_encode($mv->value), 'masked' => (bool) ($def->validation_rules['sensitive'] ?? false),
                    'source' => 'membership_transfer', 'changed_by' => $actor?->id, 'created_at' => now()]);
                $out[$def->code] = 'transferred';
            }

            return $out;
        };
        if (! $dryRun) {
            return DB::transaction($run);
        }
        DB::beginTransaction();
        try {
            return $run();
        } finally {
            DB::rollBack();
        }
    }
}
