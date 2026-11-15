<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmployeeNumbering;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-788 — numbers for employee records that have NONE (historical records). Idempotent.
 *
 *  - Only records whose `employee_number` is NULL or empty ; an existing number is never touched.
 *  - Numbers come from the per-company sequence (EmployeeNumbering::next : row lock, never reused,
 *    starts after existing numbers, skips numbers already taken) — the same path as new records.
 *  - Each write is conditional (`WHERE employee_number IS NULL`) : a record numbered meanwhile by a
 *    concurrent request is skipped and the freshly drawn number is simply left unused (never reused).
 *  - Each assignment is audited (`employee.number_assigned`).
 *  - `--dry-run` writes NOTHING (neither numbers nor counters) and shows the exact numbers.
 *
 *   php artisan employees:assign-missing-numbers --dry-run
 *   php artisan employees:assign-missing-numbers --company=1
 */
class AssignMissingEmployeeNumbersCommand extends Command
{
    protected $signature = 'employees:assign-missing-numbers {--dry-run} {--company=* : limit to these company ids}';

    protected $description = 'Give an employee number to historical records without one (simulation with --dry-run) — ADR-788';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $missing = Employee::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('employee_number')->orWhere('employee_number', ''))
            ->when($this->option('company'), fn ($q, $ids) => $q->whereIn('company_id', $ids))
            ->orderBy('company_id')->orderBy('id')->get(['id', 'company_id', 'first_name', 'last_name', 'status']);

        $report = ['dry_run' => $dry, 'total' => $missing->count(), 'companies' => []];
        foreach ($missing->groupBy('company_id') as $companyId => $employees) {
            $planned = $dry ? EmployeeNumbering::preview((int) $companyId, $employees->count()) : [];
            $rows = [];
            foreach ($employees->values() as $i => $employee) {
                if ($dry) {
                    $rows[] = ['employee_id' => $employee->id, 'status' => $employee->status, 'number' => $planned[$i]];

                    continue;
                }
                $number = EmployeeNumbering::next((int) $companyId);
                $updated = DB::table('workforce_employees')->where('id', $employee->id)
                    ->where(fn ($q) => $q->whereNull('employee_number')->orWhere('employee_number', ''))
                    ->update(['employee_number' => $number, 'updated_at' => now()]);
                if ($updated) {
                    app(AuditLogger::class)->logCompany((int) $companyId, 'employee.number_assigned', 'employee', (string) $employee->id, [
                        'diffBefore' => ['employee_number' => null], 'diffAfter' => ['employee_number' => $number],
                        'metadata' => ['category' => 'workforce.employee', 'source' => 'employees:assign-missing-numbers (ADR-788)'],
                    ]);
                }
                $rows[] = ['employee_id' => $employee->id, 'status' => $employee->status, 'number' => $updated ? $number : null, 'skipped' => ! $updated];
            }
            $report['companies'][$companyId] = $rows;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
