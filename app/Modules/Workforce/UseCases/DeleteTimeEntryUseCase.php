<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;

class DeleteTimeEntryUseCase
{
    public function execute(TimeEntry $entry): void
    {
        // Guard: cannot delete active entries (working or on_break)
        if (in_array($entry->status, [TimeEntry::STATUS_WORKING, TimeEntry::STATUS_ON_BREAK], true)) {
            throw new \DomainException("Cannot delete a time entry with status '{$entry->status}'. Clock out first.");
        }

        // Guard: check if entry is referenced in a locked/approved timesheet
        $this->guardTimesheetIntegrity($entry);

        $companyId = $entry->company_id;
        $entryData = [
            'id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'date' => $entry->date?->toDateString(),
            'clock_in' => $entry->clock_in?->toIso8601String(),
            'clock_out' => $entry->clock_out?->toIso8601String(),
            'worked_minutes' => $entry->total_worked_minutes,
        ];

        // Delete breaks first, then entry
        $entry->breaks()->delete();
        $entry->delete();

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'time_entry.deleted',
            targetType: 'time_entry',
            targetId: (string) $entryData['id'],
            options: [
                'metadata' => [
                    'category' => 'workforce.time',
                    'deleted_data' => $entryData,
                    'description' => 'Time entry deleted',
                ],
            ],
        );
    }

    private function guardTimesheetIntegrity(TimeEntry $entry): void
    {
        $lockedStatuses = ['locked', 'approved'];

        $exists = TimesheetLine::withoutGlobalScopes()
            ->whereHas('timesheetPeriod', function ($q) use ($entry, $lockedStatuses) {
                $q->where('company_id', $entry->company_id)
                    ->where('employee_id', $entry->employee_id)
                    ->whereIn('status', $lockedStatuses);
            })
            ->whereDate('date', $entry->date?->toDateString())
            ->exists();

        if ($exists) {
            throw new \DomainException('Cannot delete a time entry that is part of a locked or approved timesheet.');
        }
    }
}
