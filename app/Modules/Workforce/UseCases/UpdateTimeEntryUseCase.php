<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use Carbon\Carbon;

class UpdateTimeEntryUseCase
{
    public function execute(TimeEntry $entry, array $data, ?int $actorId = null): TimeEntry
    {
        // ADR-818 (chantier F) — OUBLI DE SORTIE : un pointage resté ouvert (en cours / en pause) se CLÔTURE par
        // une correction qui donne l'heure de sortie (motif obligatoire, contrôlé par le contrôleur). Sans heure
        // de sortie, un pointage ouvert reste non modifiable (la personne est peut-être en train de travailler).
        $closing = in_array($entry->status, [TimeEntry::STATUS_WORKING, TimeEntry::STATUS_ON_BREAK], true);
        if ($closing && empty($data['clock_out'])) {
            throw new \DomainException(__('time.open_entry_needs_clock_out'));
        }
        if (! $closing && ! in_array($entry->status, [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_IDLE], true)) {
            throw new \DomainException(__('time.entry_not_correctable'));
        }
        if ($closing && Carbon::parse($data['clock_out'])->isFuture()) {
            throw new \DomainException(__('time.clock_out_in_future'));
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
            throw new \DomainException(__('time.clock_in_before_out'));
        }

        if ($closing) {
            // Une pause restée ouverte se termine au plus tard à l'heure de sortie retenue.
            foreach ($entry->breaks()->whereNull('end_at')->get() as $break) {
                $break->end_at = $break->start_at && $break->start_at->gt($entry->clock_out) ? $break->start_at : $entry->clock_out;
                $break->computeDuration();
                $break->save();
            }
            $entry->status = TimeEntry::STATUS_COMPLETED;
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
                    'reason' => $data['reason'] ?? null,   // W-G5 : motif obligatoire (traçabilité)
                    'actor_id' => $actorId,
                    'employee_id' => $entry->employee_id,
                    'closed_forgotten_clock_out' => $closing, // ADR-818
                    'description' => ($closing ? 'Forgotten clock-out closed' : 'Time entry corrected').(isset($data['reason']) ? ' — '.$data['reason'] : ''),
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
            throw new \DomainException(__('time.locked_timesheet'));
        }
    }
}
