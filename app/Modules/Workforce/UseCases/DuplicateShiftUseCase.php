<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;

/**
 * Duplique un shift en NOUVEAU BROUILLON sur une date cible (Smart Planning SP1a.1, ADR-708).
 * Réutilise CreateShiftUseCase (tous les invariants). N'emporte PAS les activités ni le statut publié.
 */
class DuplicateShiftUseCase
{
    public function __construct(private CreateShiftUseCase $create) {}

    public function execute(Shift $shift, ?string $targetDate = null, ?int $targetEmployeeId = null): Shift
    {
        $date = Carbon::parse($shift->date);
        $target = $targetDate ? Carbon::parse($targetDate) : $date->copy()->addDay();
        $deltaDays = $date->diffInDays($target, false);

        $newStart = $shift->start_at->copy()->addDays($deltaDays);
        $newEnd = $shift->end_at->copy()->addDays($deltaDays);

        return $this->create->execute(
            companyId: $shift->company_id,
            employeeId: $targetEmployeeId ?? $shift->employee_id,
            date: $target->toDateString(),
            startAt: $newStart->toDateTimeString(),
            endAt: $newEnd->toDateTimeString(),
            timezone: $shift->timezone,
            scheduleTemplateId: $shift->schedule_template_id,
            workLocationId: $shift->work_location_id,
            notes: $shift->notes,
        );
    }
}
