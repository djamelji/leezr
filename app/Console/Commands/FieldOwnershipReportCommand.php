<?php

namespace App\Console\Commands;

use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldOwnership;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use Illuminate\Console\Command;

/**
 * ADR-795 (L5) — transition of company-user values written on the ACCOUNT (before L5). Read-only report by
 * default ; adoption is explicit, one value at a time (--adopt=code --employee=id --apply), never deletes
 * the account value, never overwrites a company value, never assigns a value to a company by guess.
 */
class FieldOwnershipReportCommand extends Command
{
    protected $signature = 'fields:ownership-report {--company=* : limit to these companies} {--adopt= : field code to adopt (requires exactly one --company)} {--employee= : employee id (adoption)} {--apply : write the adoption}';

    protected $description = 'Report dynamic values stored on accounts that belong to an employee record (no write unless --adopt --apply)';

    public function handle(): int
    {
        if ($this->option('adopt')) {
            return $this->adopt();
        }
        $companies = $this->option('company');
        $defs = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get()->keyBy('id');
        $rows = [];
        $userValues = FieldValue::where('model_type', 'user')->get();
        $employees = Employee::withoutGlobalScopes()->whereIn('user_id', $userValues->pluck('model_id')->unique())->get()->groupBy('user_id');
        $own = FieldValue::where('model_type', 'employee')->get()->keyBy(fn ($v) => $v->model_id.':'.$v->field_definition_id);
        foreach ($userValues as $v) {
            $def = $defs[$v->field_definition_id] ?? null;
            $emps = ($employees[$v->model_id] ?? collect())->filter(fn ($e) => ! $companies || in_array((string) $e->company_id, $companies, true));
            if (! $def) {
                $rows[] = [$v->model_id, '—', '?', 'unknown_field', '—'];

                continue;
            }
            $owner = FieldOwnership::of($def);
            if ($owner === FieldOwnership::PERSON) {
                $rows[] = [$v->model_id, $def->code, $owner, 'stays_on_account', $emps->pluck('company_id')->implode(',') ?: '—'];

                continue;
            }
            $category = match (true) {
                $emps->isEmpty() => 'no_employee_record',
                $emps->count() > 1 => 'ambiguous_multi_company',
                isset($own[$emps->first()->id.':'.$def->id]) => $own[$emps->first()->id.':'.$def->id]->value == $v->value ? 'duplicate_same_value' : 'conflict',
                default => 'proposed_adoption',
            };
            $rows[] = [$v->model_id, $def->code, $owner, $category, $emps->map(fn ($e) => $e->company_id.'/emp'.$e->id)->implode(',') ?: '—'];
        }
        $this->table(['user', 'field', 'owner', 'category', 'company/employee'], $rows);
        $this->line('No value has been written. Adoption: --adopt=<code> --employee=<id> --apply');

        return self::SUCCESS;
    }

    private function adopt(): int
    {
        $company = $this->option('company');
        if (count($company) !== 1) {
            $this->error('Adoption requires exactly one --company (never a guess between employers).');

            return self::FAILURE;
        }
        $employee = Employee::withoutGlobalScopes()->where('company_id', (int) $company[0])->where('id', (int) $this->option('employee'))->get()->first();
        if (! $employee) {
            $this->error('Employee not found in this company.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Dry run: would adopt « '.$this->option('adopt').' » for employee '.$employee->id.' (company '.$employee->company_id.'). Add --apply.');

            return self::SUCCESS;
        }
        $ok = (new EmployeeFieldStore(Company::findOrFail($employee->company_id)))->adoptLegacy($employee, (string) $this->option('adopt'), null);
        $this->{$ok ? 'info' : 'warn'}($ok ? 'Adopted (account value kept).' : 'Nothing adopted (not an employment field, no account value, or a company value already exists).');

        return self::SUCCESS;
    }
}
