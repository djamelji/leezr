<?php

namespace App\Core\Workforce;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * ADR-787 — automatic, readable, company-unique employee numbers (« EMP-000001 »).
 *
 *  - Monotonic per-company counter (`company_sequences`, row locked FOR UPDATE inside a transaction) :
 *    concurrent creations get distinct numbers ; never MAX()+1.
 *  - The counter only grows : a number freed by a deleted or departed employee is never handed out again.
 *  - First use in a company : the counter starts AFTER the highest existing number of the same format,
 *    so it never collides with numbers typed or imported earlier ; a manual number that would collide
 *    is skipped (the unique index `we_employee_number_unique` remains the final guard).
 *  - Existing records are never renumbered.
 */
final class EmployeeNumbering
{
    public const SEQUENCE = 'employee_number';

    public static function format(): array
    {
        return ['prefix' => (string) config('workforce.employee_number.prefix', 'EMP-'), 'pad' => (int) config('workforce.employee_number.pad', 6)];
    }

    public static function next(int $companyId): string
    {
        ['prefix' => $prefix, 'pad' => $pad] = self::format();
        self::ensureRow($companyId, $prefix); // outside the transaction : no lock contention on first use

        // Retried on deadlock (MySQL may abort one of two transactions competing for the row lock).
        return DB::transaction(function () use ($companyId, $prefix, $pad) {
            $row = DB::table('company_sequences')->where('company_id', $companyId)->where('name', self::SEQUENCE)->lockForUpdate()->first();
            $value = (int) $row->next_value;
            do {
                $number = $prefix.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
                $taken = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_number', $number)->exists();
                $value++;
            } while ($taken);
            DB::table('company_sequences')->where('id', $row->id)->update(['next_value' => $value, 'updated_at' => now()]);

            return $number;
        }, 5);
    }

    /** First use in a company : the counter starts after the highest existing number of this format. */
    private static function ensureRow(int $companyId, string $prefix): void
    {
        if (DB::table('company_sequences')->where('company_id', $companyId)->where('name', self::SEQUENCE)->exists()) {
            return;
        }
        $max = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_number', 'like', $prefix.'%')
            ->pluck('employee_number')->map(fn ($n) => ctype_digit($s = substr($n, strlen($prefix))) ? (int) $s : 0)->max() ?? 0;
        // INSERT IGNORE : concurrent first uses converge on one row (unique company_id + name).
        DB::table('company_sequences')->insertOrIgnore(['company_id' => $companyId, 'name' => self::SEQUENCE, 'next_value' => $max + 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Updates the record and keeps the history of a number change. */
    public static function updateTracked(Employee $employee, array $attributes): void
    {
        $before = $employee->employee_number;
        $employee->update($attributes);
        self::recordChange($employee, $before);
    }

    /** History of an HR change of number (identity and historical references are untouched). */
    public static function recordChange(Employee $employee, ?string $before): void
    {
        if ($before === $employee->employee_number) {
            return;
        }
        app(AuditLogger::class)->logCompany($employee->company_id, 'employee.number_changed', 'employee', (string) $employee->id, [
            'diffBefore' => ['employee_number' => $before], 'diffAfter' => ['employee_number' => $employee->employee_number],
            'metadata' => ['category' => 'workforce.employee'],
        ]);
    }
}
