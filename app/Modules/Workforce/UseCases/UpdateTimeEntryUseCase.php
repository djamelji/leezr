<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use Carbon\Carbon;

class UpdateTimeEntryUseCase
{
    public function execute(TimeEntry $entry, array $data): TimeEntry
    {
        // Guard: only completed or idle entries can be corrected
        if (! in_array($entry->status, [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_IDLE], true)) {
            throw new \DomainException("Cannot update a time entry with status '{$entry->status}'. Only completed or idle entries can be corrected.");
        }

        // Guard: check if entry is referenced in a locked/approved timesheet
        $this->guardTimesheetIntegrity($entry);

        $oldData = [
            'date' => $entry->date?->toDateString(),
            'clock_in' => $entry->clock_in?->toIso8601String(),
            'clock_out' => $entry->clock_out?->toIso8601String(),
        ];

        if (isset($data['date'])) {
            $entry->date = Carbon::parse($data['date']);
        }

        if (isset($data['clock_in'])) {
            $entry->clock_in = Carbon::parse($data['clock_in']);
        }

        if (isset($data['clock_out'])) {
            $entry->clock_out = Carbon::parse($data['clock_out']);
        }

        // Validate clock_in < clock_out
        if ($entry->clock_in && $entry->clock_out && $entry->clock_in->gte($entry->clock_out)) {
            throw new \DomainException('Clock in must be before clock out.');
        }

        // Recompute totals if both clock times present
        if ($entry->clock_in && $entry->clock_out) {
            $entry->computeTotals();
        }

        $entry->save();

        app(AuditLogger::class)->logCompany(
            companyId: $entry->company_id,
            action: 'time_entry.updated',
            targetType: 'time_entry',
            targetId: (string) $entry->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.time',
                    'old_data' => $oldData,
                    'new_data' => [
                        'date' => $entry->date?->toDateString(),
                        'clock_in' => $entry->clock_in?->toIso8601String(),
                        'clock_out' => $entry->clock_out?->toIso8601String(),
                    ],
                    'description' => 'Time entry corrected',
                ],
            ],
        );

        return $entry;
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
            throw new \DomainException('Cannot modify a time entry that is part of a locked or approved timesheet.');
        }
    }
}
