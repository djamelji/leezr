<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\PlanningOperation;
use App\Core\Workforce\Shift;
use Illuminate\Support\Collection;

/**
 * Enregistre les opérations de planning RÉVERSIBLES (Smart Planning Lot 4B.3-B, ADR-722).
 * Appelé par les contrôleurs APRÈS une écriture réussie ; l'id retourné alimente la pile undo/redo du client.
 */
class PlanningOperationRecorder
{
    /** Opération « création » : annuler = supprimer les brouillons créés ; rétablir = réexécuter l'intention. */
    public function recordCreated(int $companyId, ?int $userId, string $type, array $createdShiftIds, array $intent): PlanningOperation
    {
        return PlanningOperation::create([
            'company_id' => $companyId, 'user_id' => $userId, 'type' => $type,
            'created_shift_ids' => array_values(array_filter($createdShiftIds)),
            'intent' => $intent, 'status' => PlanningOperation::STATUS_ACTIVE,
        ]);
    }

    /** Déplacement d'un brouillon : stocke before (snapshot pris AVANT) / after (+ updated_at pour le verrou). */
    public function recordMove(int $companyId, ?int $userId, array $before, Shift $after): PlanningOperation
    {
        $afterSnap = self::snapshot($after);

        return PlanningOperation::create([
            'company_id' => $companyId, 'user_id' => $userId, 'type' => PlanningOperation::TYPE_MOVE,
            'created_shift_ids' => [],
            'move_payload' => ['shift_id' => $after->id, 'before' => $before, 'after' => $afterSnap],
            'intent' => ['shift_id' => $after->id, 'target' => $afterSnap],
            'status' => PlanningOperation::STATUS_ACTIVE,
        ]);
    }

    /** Snapshot « before » d'un shift avant déplacement (horaires/jour/salarié + updated_at). */
    public static function snapshot(Shift $s): array
    {
        return [
            'date' => $s->date->toDateString(),
            'start_at' => $s->start_at->toDateTimeString(),
            'end_at' => $s->end_at->toDateTimeString(),
            'employee_id' => $s->employee_id,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
