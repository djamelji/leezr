<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remplacement guidé (Smart Planning Lot 3 complément, ADR-717).
 *
 * Crée un NOUVEAU shift pour le remplaçant en RÉUTILISANT les UseCases existants :
 *  - CreateShiftUseCase → re-valide tous les invariants (salarié actif, contrat actif, pas de congé,
 *    pas de double-booking, cohérence horaire, cross-company) pour le remplaçant ;
 *  - PublishShiftsUseCase → publication atomique (verrou + re-validation au publish).
 *
 * Invariants respectés :
 *  - Le Shift initial publié n'est NI supprimé NI réaffecté (préservé tel quel).
 *  - Lien d'audit via replaces_shift_id (initiale ↔ remplacement).
 *  - Fenêtre partielle : on remplace uniquement [startAt,endAt] (créneau non assuré).
 *  - Pas de double-comptage : l'absent est exclu de la couverture sur sa fenêtre (ADR-717) ; le
 *    remplaçant, réellement affecté, compte une seule fois.
 */
class CreateReplacementShiftUseCase
{
    public function execute(
        int $companyId,
        int $originalShiftId,
        int $replacementEmployeeId,
        string $startAt,
        string $endAt,
        ?int $reviewerId = null,
    ): Shift {
        $original = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)->findOrFail($originalShiftId);

        // Le remplacement ne s'applique qu'à une affectation PUBLIÉE (un brouillon se modifie directement).
        if ($original->status !== Shift::STATUS_PUBLISHED) {
            throw new \DomainException('Only a published shift can be replaced.');
        }

        $start = Carbon::parse($startAt);
        $end = Carbon::parse($endAt);

        // La fenêtre de remplacement doit tenir dans le shift initial (on ne remplace pas au-delà).
        if ($start->lt($original->start_at) || $end->gt($original->end_at)) {
            throw new \DomainException('Replacement window must fall within the original shift.');
        }
        if ($replacementEmployeeId === (int) $original->employee_id) {
            throw new \DomainException('Replacement must be a different employee than the original.');
        }

        return DB::transaction(function () use (
            $companyId, $original, $replacementEmployeeId, $start, $end, $reviewerId
        ) {
            // 1) Brouillon pour le remplaçant (hérite modèle/lieu → même catégorie de couverture).
            $draft = app(CreateShiftUseCase::class)->execute(
                companyId: $companyId,
                employeeId: $replacementEmployeeId,
                date: $original->date->toDateString(),
                startAt: $start->toDateTimeString(),
                endAt: $end->toDateTimeString(),
                timezone: $original->timezone,
                scheduleTemplateId: $original->schedule_template_id,
                workLocationId: $original->work_location_id,
                notes: null,
            );

            // 2) Lien d'audit vers l'affectation initiale.
            $draft->replaces_shift_id = $original->id;
            $draft->save();

            // 3) Publication atomique (re-valide au publish).
            app(PublishShiftsUseCase::class)->execute($companyId, [$draft->id]);

            app(AuditLogger::class)->logCompany(
                companyId: $companyId,
                action: 'shift.replacement_created',
                targetType: 'shift',
                targetId: (string) $draft->id,
                options: [
                    'metadata' => [
                        'category' => 'workforce.planning',
                        'original_shift_id' => $original->id,
                        'original_employee_id' => $original->employee_id,
                        'replacement_employee_id' => $replacementEmployeeId,
                        'window_start' => $start->toDateTimeString(),
                        'window_end' => $end->toDateTimeString(),
                        'reviewer_id' => $reviewerId,
                    ],
                ],
            );

            return $draft->fresh();
        });
    }
}
