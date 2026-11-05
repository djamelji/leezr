<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\PlanningOperation;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ANNULER / RÉTABLIR une opération de planning (Smart Planning Lot 4B.3-B, ADR-722).
 *
 * Commandes INVERSES réellement persistées, validées côté serveur — jamais une restauration aveugle.
 * ANNULER : type « création » → suppression ATOMIQUE des brouillons créés (refus si l'un est publié/
 * modifié) ; déplacement → retour aux coordonnées initiales via UpdateShiftUseCase (verrou optimiste,
 * reconflits/absences/réglementaire/sureffactif revalidés). RÉTABLIR : réexécute l'INTENTION métier avec
 * les contrôles actuels (jamais de recréation d'un ancien ID). Immutabilité publiée préservée. Idempotent.
 */
class PlanningUndoRedoUseCase
{
    /** @return array<string,mixed> */
    public function undo(PlanningOperation $op, int $userId, ?string $ack = null, ?string $reason = null): array
    {
        $this->guardOwnership($op, $userId);
        if ($op->status !== PlanningOperation::STATUS_ACTIVE) {
            throw new \DomainException('Operation already undone.'); // idempotence / double-clic
        }

        return DB::transaction(function () use ($op, $ack, $reason) {
            if ($op->isCreateType()) {
                $this->deleteCreated($op);
            } else {
                $this->moveBack($op, $ack, $reason);
            }

            $op->update(['status' => PlanningOperation::STATUS_UNDONE, 'undone_at' => now()]);
            app(AuditLogger::class)->logCompany(
                companyId: $op->company_id, action: 'planning.operation_undone',
                targetType: 'planning_operation', targetId: (string) $op->id,
                options: ['metadata' => ['category' => 'workforce.planning', 'type' => $op->type]],
            );

            return ['status' => 'undone', 'operation_id' => $op->id, 'type' => $op->type];
        });
    }

    /** @return array<string,mixed> */
    public function redo(PlanningOperation $op, int $userId, ?string $ack = null, ?string $reason = null): array
    {
        $this->guardOwnership($op, $userId);
        if ($op->status !== PlanningOperation::STATUS_UNDONE) {
            throw new \DomainException('Operation is not undone.');
        }

        return DB::transaction(function () use ($op, $ack, $reason) {
            if ($op->isCreateType()) {
                $created = $this->reexecuteCreate($op, $ack, $reason);
                $op->created_shift_ids = $created; // nouveaux IDs (jamais l'ancien)
            } else {
                $this->moveForward($op, $ack, $reason);
            }
            $op->status = PlanningOperation::STATUS_ACTIVE;
            $op->redone_at = now();
            $op->save();

            app(AuditLogger::class)->logCompany(
                companyId: $op->company_id, action: 'planning.operation_redone',
                targetType: 'planning_operation', targetId: (string) $op->id,
                options: ['metadata' => ['category' => 'workforce.planning', 'type' => $op->type]],
            );

            return ['status' => 'redone', 'operation_id' => $op->id, 'type' => $op->type, 'created' => $op->created_shift_ids];
        });
    }

    private function guardOwnership(PlanningOperation $op, int $userId): void
    {
        // Jamais rejouable par un autre utilisateur/tenant : l'opération appartient à son initiateur.
        if ((int) $op->user_id !== $userId) {
            throw new \DomainException('Not authorized to undo/redo this operation.');
        }
    }

    /** Suppression ATOMIQUE des brouillons créés ; refus explicite si l'un est publié (jamais partiel). */
    private function deleteCreated(PlanningOperation $op): void
    {
        $shifts = Shift::withoutGlobalScopes()
            ->where('company_id', $op->company_id)
            ->whereIn('id', $op->created_shift_ids ?? [])->get();

        foreach ($shifts as $s) {
            if ($s->status !== Shift::STATUS_DRAFT) {
                // Un élément est devenu publié → on n'annule RIEN (pas 29/30 en silence).
                throw new \DomainException('Cannot undo: a created shift is no longer a draft (published or modified).');
            }
        }
        foreach ($shifts as $s) {
            $s->scheduledActivities()->delete();
            $s->delete();
        }
    }

    /** Retour aux coordonnées d'origine via UpdateShiftUseCase (verrou optimiste + revalidation complète). */
    private function moveBack(PlanningOperation $op, ?string $ack, ?string $reason): void
    {
        $p = $op->move_payload;
        $shift = Shift::withoutGlobalScopes()->where('company_id', $op->company_id)->find($p['shift_id']);
        if (! $shift) {
            throw new \DomainException('Cannot undo: shift no longer exists.');
        }
        if ($shift->status !== Shift::STATUS_DRAFT) {
            throw new \DomainException('Cannot undo: shift is published.');
        }
        // Concurrence : si le shift a changé depuis le déplacement (updated_at ≠ after), refus explicite.
        if ($p['after']['updated_at'] && Carbon::parse($p['after']['updated_at'])->ne($shift->updated_at)) {
            throw new \DomainException('CONCURRENT_CHANGE: shift modified by someone else since the move.');
        }
        $b = $p['before'];
        app(UpdateShiftUseCase::class)->execute($shift, [
            'employee_id' => $b['employee_id'], 'date' => $b['date'],
            'start_at' => $b['start_at'], 'end_at' => $b['end_at'],
        ], $ack, $reason);

        // Mémorise le nouvel état pour un éventuel redo (coords after inchangées ; after.updated_at rafraîchi).
        $p['before']['updated_at'] = $shift->fresh()->updated_at?->toIso8601String();
        $op->move_payload = $p;
    }

    private function moveForward(PlanningOperation $op, ?string $ack, ?string $reason): void
    {
        $p = $op->move_payload;
        $shift = Shift::withoutGlobalScopes()->where('company_id', $op->company_id)->find($p['shift_id']);
        if (! $shift || $shift->status !== Shift::STATUS_DRAFT) {
            throw new \DomainException('Cannot redo: shift unavailable or published.');
        }
        if ($p['before']['updated_at'] && Carbon::parse($p['before']['updated_at'])->ne($shift->updated_at)) {
            throw new \DomainException('CONCURRENT_CHANGE: shift modified by someone else.');
        }
        $a = $p['after'];
        app(UpdateShiftUseCase::class)->execute($shift, [
            'employee_id' => $a['employee_id'], 'date' => $a['date'],
            'start_at' => $a['start_at'], 'end_at' => $a['end_at'],
        ], $ack, $reason);
        $p['after']['updated_at'] = $shift->fresh()->updated_at?->toIso8601String();
        $op->move_payload = $p;
    }

    /** Réexécute l'intention métier (create/duplicate/copy/copy_week/bulk) avec les contrôles ACTUELS. */
    private function reexecuteCreate(PlanningOperation $op, ?string $ack, ?string $reason): array
    {
        $i = $op->intent;
        $companyId = $op->company_id;

        return match ($op->type) {
            'create_shift' => [app(CreateShiftUseCase::class)->execute(
                companyId: $companyId, employeeId: $i['employee_id'], date: $i['date'],
                startAt: $i['start_at'], endAt: $i['end_at'], timezone: $i['timezone'] ?? 'Europe/Paris',
                scheduleTemplateId: $i['schedule_template_id'] ?? null, workLocationId: $i['work_location_id'] ?? null,
                notes: $i['notes'] ?? null, overstaffingAck: $ack, overstaffingReason: $reason,
            )->id],
            'duplicate' => [app(DuplicateShiftUseCase::class)->execute(
                Shift::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($i['source_shift_id']),
                $i['target_date'] ?? null, $i['target_employee_id'] ?? null,
            )->id],
            'copy' => app(CopyShiftsUseCase::class)->commit($companyId, $i['operations'], null, $ack, $reason, $op->user_id)['created'],
            'bulk_template' => app(BulkApplyTemplateUseCase::class)->commit($companyId, $i['schedule_template_id'], $i['cells'], null, $ack, $reason, $op->user_id)['created'],
            default => throw new \DomainException('Redo not supported for type '.$op->type),
        };
    }
}
