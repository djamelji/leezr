<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;

/**
 * Matérialise les activités d'une composition de modèle (Refonte Smart Planning — M1/M1.b, ADR-724).
 *
 * Lit la composition FIGÉE dans le snapshot du shift (`template_snapshot['activities']`, offsets relatifs)
 * et crée de vraies ScheduledActivity (prévisionnel), chacune validée par ScheduledActivityValidator
 * (AUTORITÉ unique : bornes du shift, pause, chevauchement, capacité/union). Doit être appelé DANS la
 * transaction du use case appelant → une activité invalide lève et annule l'ensemble (atomicité).
 *
 * Source unique partagée par CreateShiftUseCase (store / bulk-template) et BulkCreateShiftsUseCase
 * (shifts/bulk) — pas de double représentation ni de logique dupliquée. JAMAIS sur un chemin de COPIE
 * (CopyShiftsUseCase recopie déjà les vraies activités) pour éviter toute double-matérialisation.
 */
class CompositionMaterializer
{
    public function __construct(private ScheduledActivityValidator $validator) {}

    /** @return int nombre d'activités matérialisées */
    public function materializeFromSnapshot(int $companyId, Shift $shift): int
    {
        $activities = $shift->template_snapshot['activities'] ?? null;
        if (! is_array($activities) || ! $activities) {
            return 0;
        }

        $count = 0;
        foreach ($activities as $a) {
            $offset = (int) ($a['offset_minutes'] ?? 0);
            $duration = (int) ($a['duration_minutes'] ?? 0);
            if ($duration <= 0) {
                throw new \DomainException('Template activity has a non-positive duration.');
            }
            $start = $shift->start_at->copy()->addMinutes($offset);
            $end = $start->copy()->addMinutes($duration);

            $nonExclusive = (bool) ($a['non_exclusive'] ?? false);
            // Autorité : bornes du shift, pause, chevauchement, capacité (union). Atomique si throw.
            $this->validator->assertValid($shift, $start, $end, ['non_exclusive' => $nonExclusive]);

            $priority = $a['priority'] ?? 'normal';
            ScheduledActivity::create([
                'company_id' => $companyId,
                'shift_id' => $shift->id,
                'employee_id' => $shift->employee_id, // dérivé du shift (invariant)
                'schedule_template_id' => $shift->schedule_template_id,
                'work_location_id' => $a['work_location_id'] ?? $shift->work_location_id,
                'activity_type' => $a['activity_type'] ?? null,
                'coverage_category_id' => $a['coverage_category_id'] ?? null,
                'label' => $a['label'] ?? null,
                'start_at' => $start,
                'end_at' => $end,
                'status' => ScheduledActivity::STATUS_PLANNED,
                'priority' => in_array($priority, ScheduledActivity::PRIORITIES, true) ? $priority : 'normal',
                'source' => 'template',
                'metadata' => $nonExclusive ? ['non_exclusive' => true] : null,
            ]);
            $count++;
        }

        return $count;
    }
}
