<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
use Illuminate\Support\Carbon;

/**
 * Copie d'une semaine de planning (Smart Planning SP1a.1, ADR-708).
 *
 * CONTRAT EXPLICITE : chaque shift source (brouillon OU publié, non annulé) est recréé comme NOUVEAU
 * BROUILLON décalé sur la semaine cible — on ne duplique JAMAIS un statut publié, ni les activités, ni
 * l'historique. Réutilise CreateShiftUseCase → tous les invariants (congés, double-booking, règles,
 * immutabilité) s'appliquent ; un shift en conflit est IGNORÉ (compté), pas forcé.
 *
 * @return array{created:int, skipped:int, skipped_details:list<string>}
 */
class CopyWeekUseCase
{
    public function __construct(private CreateShiftUseCase $create) {}

    public function execute(int $companyId, string $sourceWeekStart, string $targetWeekStart): array
    {
        $source = Carbon::parse($sourceWeekStart)->startOfDay();
        $target = Carbon::parse($targetWeekStart)->startOfDay();
        $deltaDays = $source->diffInDays($target, false);

        if ($deltaDays === 0) {
            throw new \DomainException('Source and target weeks must differ.');
        }

        $shifts = ShiftReadModel::forWeek($companyId, $source);

        $created = 0;
        $skipped = 0;
        $details = [];

        foreach ($shifts as $shift) {
            $newStart = $shift->start_at->copy()->addDays($deltaDays);
            $newEnd = $shift->end_at->copy()->addDays($deltaDays);
            $newDate = Carbon::parse($shift->date)->copy()->addDays($deltaDays)->toDateString();

            try {
                $this->create->execute(
                    companyId: $companyId,
                    employeeId: $shift->employee_id,
                    date: $newDate,
                    startAt: $newStart->toDateTimeString(),
                    endAt: $newEnd->toDateTimeString(),
                    timezone: $shift->timezone,
                    scheduleTemplateId: $shift->schedule_template_id,
                    workLocationId: $shift->work_location_id,
                    notes: $shift->notes,
                );
                $created++;
            } catch (\DomainException $e) {
                $skipped++;
                $details[] = "#{$shift->id}: {$e->getMessage()}";
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'skipped_details' => $details];
    }
}
