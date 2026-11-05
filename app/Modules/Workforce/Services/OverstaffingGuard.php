<?php

namespace App\Modules\Workforce\Services;

use App\Core\Audit\AuditLogger;
use App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired;

/**
 * Garde de SUREFFECTIF partagé par les UseCases d'écriture (ADR-719).
 *
 * Appelé DANS la transaction, APRÈS les validations réglementaires (qui restent bloquantes et prioritaires)
 * et APRÈS l'acquisition des verrous → le calcul reflète l'état committé courant, sans fenêtre de
 * concurrence : deux écritures confirmées simultanées recalculent chacune dans leur transaction ; la
 * seconde voit l'effet de la première → sa signature diffère de l'ack → nouvelle confirmation exigée.
 *
 * Selon la politique : warn (laisse passer), warn_confirm / warn_confirm_reason (exige ack serveur valide).
 * Journalise toute confirmation d'override. Ne crée jamais de dérogation réglementaire.
 */
class OverstaffingGuard
{
    public function __construct(
        private OverstaffingEvaluator $evaluator,
        private OverstaffingPolicy $policy,
    ) {}

    /**
     * @param  array<string,mixed>  $prospect  employee_id,date,start_at,end_at,work_location_id,template_snapshot,ignore_shift_id
     * @return array<string,mixed>  diagnostic (pour journalisation côté appelant)
     */
    public function enforce(int $companyId, array $prospect, ?string $ack, ?string $reason, ?int $actorId = null): array
    {
        $eval = $this->evaluator->evaluate($companyId, $prospect);
        if (! $eval['overstaffed']) {
            return ['overstaffed' => false];
        }

        $mode = $this->policy->mode($companyId);

        if ($this->policy->requiresConfirmation($mode)) {
            $needsReason = $this->policy->requiresReason($mode);
            $ackValid = $ack !== null && hash_equals($eval['signature'], $ack);
            if (! $ackValid || ($needsReason && trim((string) $reason) === '')) {
                throw new OverstaffingConfirmationRequired($eval['impacts'], $eval['signature'], $mode, $needsReason);
            }
        }

        // Override accepté (ou mode warn) → journalisation de la décision opérationnelle.
        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'shift.overstaffing_confirmed',
            targetType: 'shift',
            targetId: $prospect['ignore_shift_id'] ? (string) $prospect['ignore_shift_id'] : 'new',
            options: ['metadata' => [
                'category' => 'workforce.planning',
                'mode' => $mode,
                'impacts' => $eval['impacts'],
                'reason' => $reason,
                'actor_id' => $actorId,
            ]],
        );

        return ['overstaffed' => true, 'mode' => $mode, 'impacts' => $eval['impacts'], 'confirmed' => true];
    }
}
