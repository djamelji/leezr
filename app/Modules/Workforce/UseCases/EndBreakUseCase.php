<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimeEntryBreak;

class EndBreakUseCase
{
    public function execute(TimeEntry $entry): TimeEntryBreak
    {
        if ($entry->status !== TimeEntry::STATUS_ON_BREAK) {
            throw new \DomainException("Cannot end break: time entry is in status {$entry->status}");
        }

        $openBreak = $entry->breaks()->whereNull('end_at')->first();

        if (! $openBreak) {
            throw new \DomainException('No open break found.');
        }

        $openBreak->end_at = now();
        $openBreak->computeDuration();
        $openBreak->save();

        $entry->transitionTo(TimeEntry::STATUS_WORKING);
        $entry->save();

        app(AuditLogger::class)->logCompany(
            companyId: $entry->company_id,
            action: 'time_entry.break_end',
            targetType: 'time_entry',
            targetId: (string) $entry->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.time',
                    'break_id' => $openBreak->id,
                    'employee_id' => $entry->employee_id,
                    'duration_minutes' => $openBreak->duration_minutes,
                    'occurred_at' => now()->toIso8601String(),
                    'description' => "Break ended ({$openBreak->type}, {$openBreak->duration_minutes}min)",
                ],
            ],
        );

        return $openBreak;
    }
}
