<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;

class ClockInUseCase
{
    public function execute(Employee $employee, string $source = 'manual'): TimeEntry
    {
        if (! in_array($source, TimeEntry::SOURCES, true)) {
            throw new \DomainException("Invalid source '{$source}'. Allowed: " . implode(', ', TimeEntry::SOURCES));
        }

        // Guard: no active time entry
        $active = TimeEntry::where('employee_id', $employee->id)
            ->active()
            ->first();

        if ($active) {
            throw new \DomainException('Employee already has an active time entry. Clock out first.');
        }

        $now = now();

        $entry = TimeEntry::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'date' => $now->toDateString(),
            'clock_in' => $now,
            'status' => TimeEntry::STATUS_WORKING,
            'source' => $source,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $employee->company_id,
            action: 'time_entry.clock_in',
            targetType: 'time_entry',
            targetId: (string) $entry->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.time',
                    'source' => $source,
                    'description' => "Clock in for {$employee->full_name}",
                ],
            ],
        );

        return $entry;
    }
}
