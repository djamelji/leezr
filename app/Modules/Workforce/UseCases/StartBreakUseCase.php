<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimeEntryBreak;

class StartBreakUseCase
{
    public function execute(TimeEntry $entry, string $type = 'rest'): TimeEntryBreak
    {
        if (! in_array($type, TimeEntryBreak::TYPES, true)) {
            throw new \DomainException("Invalid break type '{$type}'. Allowed: " . implode(', ', TimeEntryBreak::TYPES));
        }

        if ($entry->status !== TimeEntry::STATUS_WORKING) {
            throw new \DomainException("Cannot start break: time entry is in status {$entry->status}");
        }

        // Guard: no open break
        $openBreak = $entry->breaks()->whereNull('end_at')->first();

        if ($openBreak) {
            throw new \DomainException('A break is already in progress.');
        }

        $entry->transitionTo(TimeEntry::STATUS_ON_BREAK);
        $entry->save();

        $break = TimeEntryBreak::create([
            'company_id' => $entry->company_id,
            'time_entry_id' => $entry->id,
            'start_at' => now(),
            'type' => $type,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $entry->company_id,
            action: 'time_entry.break_start',
            targetType: 'time_entry',
            targetId: (string) $entry->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.time',
                    'break_id' => $break->id,
                    'employee_id' => $entry->employee_id,
                    'occurred_at' => now()->toIso8601String(),
                    'description' => "Break started ({$type})",
                ],
            ],
        );

        return $break;
    }
}
