<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimeEntryBreak;

class ClockOutUseCase
{
    public function execute(TimeEntry $entry): TimeEntry
    {
        if ($entry->status === TimeEntry::STATUS_COMPLETED) {
            throw new \DomainException('Time entry is already completed.');
        }

        // Close any open break
        $openBreak = $entry->breaks()->whereNull('end_at')->first();

        if ($openBreak) {
            $openBreak->end_at = now();
            $openBreak->computeDuration();
            $openBreak->save();
        }

        $entry->clock_out = now();
        $entry->transitionTo(TimeEntry::STATUS_COMPLETED);
        $entry->computeTotals();
        $entry->save();

        app(AuditLogger::class)->logCompany(
            companyId: $entry->company_id,
            action: 'time_entry.clock_out',
            targetType: 'time_entry',
            targetId: (string) $entry->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.time',
                    'worked_minutes' => $entry->total_worked_minutes,
                    'break_minutes' => $entry->total_break_minutes,
                    'description' => "Clock out — {$entry->total_worked_minutes} min worked",
                ],
            ],
        );

        return $entry;
    }
}
