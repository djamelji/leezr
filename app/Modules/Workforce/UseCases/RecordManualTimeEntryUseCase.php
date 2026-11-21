<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;

/**
 * ADR-848 (Leezr 4.2 M9) — a completed time entry typed by a manager (date, arrival, departure), totals computed by
 * the entry itself. Moved verbatim from TimeEntryController::store (same fields, same `manual` source).
 */
class RecordManualTimeEntryUseCase
{
    public function execute(int $companyId, Employee $employee, string $date, string $clockIn, string $clockOut): TimeEntry
    {
        $entry = TimeEntry::create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'date' => $date,
            'clock_in' => Carbon::parse($clockIn),
            'clock_out' => Carbon::parse($clockOut),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
        ]);

        $entry->computeTotals();
        $entry->update([
            'total_worked_minutes' => $entry->total_worked_minutes,
            'total_break_minutes' => $entry->total_break_minutes,
        ]);

        return $entry;
    }
}
