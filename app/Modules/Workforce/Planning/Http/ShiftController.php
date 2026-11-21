<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\Shift;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\UseCases\BulkCreateShiftsUseCase;
use App\Modules\Workforce\UseCases\CancelShiftUseCase;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use App\Modules\Workforce\UseCases\UpdateShiftUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    use \App\Modules\Workforce\Http\Concerns\PlanningScope;
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'timezone' => 'required|string|max:50',
            'schedule_template_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
            'overstaffing_ack' => 'nullable|string',               // ADR-719 : confirmation serveur
            'overstaffing_reason' => 'nullable|string|max:500',
            'derogation_reason' => 'nullable|string|max:500', // ADR-799 : dérogation à une politique société « approbation »
        ]);

        try {
            $companyId = $request->attributes->get('company_id');
            $shift = app(CreateShiftUseCase::class)->execute(
                companyId: $companyId,
                employeeId: $validated['employee_id'],
                date: $validated['date'],
                startAt: $validated['start_at'],
                endAt: $validated['end_at'],
                timezone: $validated['timezone'],
                scheduleTemplateId: $validated['schedule_template_id'] ?? null,
                workLocationId: $validated['work_location_id'] ?? null,
                notes: $validated['notes'] ?? null,
                overstaffingAck: $validated['overstaffing_ack'] ?? null,
                overstaffingReason: $validated['overstaffing_reason'] ?? null,
                actorId: $request->user()?->id,
                derogationReason: $validated['derogation_reason'] ?? null,
                // M1 (ADR-724) : application d'un modèle = matérialise ses activités préconfigurées.
                // Création manuelle (sans modèle) ⇒ pas de composition à matérialiser.
                materializeComposition: ($validated['schedule_template_id'] ?? null) !== null,
            );
            // ADR-722 — opération réversible.
            $op = app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->recordCreated(
                $companyId, $request->user()?->id, 'create_shift', [$shift->id],
                collect($validated)->only(['employee_id', 'date', 'start_at', 'end_at', 'timezone', 'schedule_template_id', 'work_location_id', 'notes'])->all(),
            );

            return response()->json($shift->toArray() + ['operation_id' => $op->id], 201);
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            return response()->json($e->toPayload(), 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** ADR-719 — prévisualisation lecture seule du sureffectif d'une affectation envisagée (+ signature). */
    public function overstaffingPreview(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'work_location_id' => 'nullable|integer',
            'schedule_template_id' => 'nullable|integer',
            'ignore_shift_id' => 'nullable|integer',
        ]);

        if ($deny = $this->guardShiftScope($request, (int) $validated['employee_id'])) {
            return $deny;
        }

        $snapshot = [];
        if (! empty($validated['schedule_template_id'])) {
            $tpl = \App\Core\Workforce\ScheduleTemplate::withoutGlobalScopes()
                ->where('company_id', $companyId)->find($validated['schedule_template_id']);
            $snapshot = $tpl ? $tpl->toSnapshot() : [];
        } elseif (! empty($validated['ignore_shift_id'])) {
            $src = Shift::withoutGlobalScopes()->where('company_id', $companyId)->find($validated['ignore_shift_id']);
            $snapshot = $src?->template_snapshot ?? [];
        }

        $eval = app(\App\Modules\Workforce\Services\OverstaffingEvaluator::class)->evaluate($companyId, [
            'employee_id' => (int) $validated['employee_id'],
            'date' => $validated['date'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'work_location_id' => $validated['work_location_id'] ?? null,
            'template_snapshot' => $snapshot,
            'ignore_shift_id' => $validated['ignore_shift_id'] ?? null,
        ]);
        $mode = app(\App\Modules\Workforce\Services\OverstaffingPolicy::class)->mode($companyId);

        return response()->json($eval + ['mode' => $mode]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate([
            'date' => 'nullable|date',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'timezone' => 'nullable|string|max:50',
            'schedule_template_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
            'employee_id' => 'nullable|integer',                 // W-G4B : réaffectation (brouillon)
            'expected_updated_at' => 'nullable|date',            // W-G4B : verrou optimiste (concurrence)
            'overstaffing_ack' => 'nullable|string',             // ADR-719
            'overstaffing_reason' => 'nullable|string|max:500',
        ]);
        $ovAck = $validated['overstaffing_ack'] ?? null;
        $ovReason = $validated['overstaffing_reason'] ?? null;
        unset($validated['overstaffing_ack'], $validated['overstaffing_reason']);

        // W-G4B — VERROU OPTIMISTE : si le client fournit l'horodatage lu et qu'il ne correspond plus,
        // une autre modification concurrente est intervenue → 409 (pas d'écrasement silencieux).
        if (! empty($validated['expected_updated_at'])
            && Carbon::parse($validated['expected_updated_at'])->ne($shift->updated_at)) {
            return response()->json([
                'message' => 'Ce shift a été modifié par une autre personne entre-temps. Rechargez puis réessayez.',
                'code' => 'stale_version',
                'current_updated_at' => $shift->updated_at?->toIso8601String(),
            ], 409);
        }

        // W-G4B — SCOPE MANAGER sur réaffectation : on ne peut affecter qu'un collaborateur visible,
        // sauf vue globale (planning_manage/publish ou RH). Le backend reste la source d'autorité.
        if (! empty($validated['employee_id']) && (int) $validated['employee_id'] !== $shift->employee_id) {
            $visible = $this->planningScope($request);
            if ($visible !== null && ! in_array((int) $validated['employee_id'], $visible, true)) {
                return response()->json(['message' => 'Collaborateur hors de votre périmètre de gestion.'], 403);
            }
        }

        unset($validated['expected_updated_at']);

        try {
            // ADR-722 — snapshot AVANT (pour l'annulation d'un déplacement).
            $before = \App\Modules\Workforce\Services\PlanningOperationRecorder::snapshot($shift);
            $updated = app(UpdateShiftUseCase::class)->execute($shift, $validated, $ovAck, $ovReason, $request->user()?->id);

            $payload = $updated->toArray();
            // Opération réversible seulement si jour/salarié/horaires ont réellement changé (vrai déplacement).
            $moved = $before['date'] !== $updated->date->toDateString()
                || (int) $before['employee_id'] !== (int) $updated->employee_id
                || $before['start_at'] !== $updated->start_at->toDateTimeString();
            if ($moved) {
                $op = app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)
                    ->recordMove($shift->company_id, $request->user()?->id, $before, $updated->fresh());
                $payload['operation_id'] = $op->id;
            }

            return response()->json($payload);
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            return response()->json($e->toPayload(), 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        if ($shift->status !== Shift::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft shifts can be deleted.'], 422);
        }

        $shift->delete();

        return response()->json(['message' => 'Shift deleted.']);
    }

    public function publish(Request $request): JsonResponse
    {
        $validated = $request->validate(['shift_ids' => 'required|array|min:1', 'shift_ids.*' => 'integer']);

        return $this->domain(function () use ($request, $validated) {
            $shifts = app(PublishShiftsUseCase::class)->execute(companyId: $request->attributes->get('company_id'), shiftIds: $validated['shift_ids']);
            // ADR-717 — l'intention a été enregistrée dans la transaction (outbox) ; flush post-commit
            // immédiat (la commande planifiée rattrape tout échec ou crash post-commit). Idempotent.
            app(\App\Modules\Workforce\Services\PlanningOutbox::class)->flush();

            return $shifts;
        });
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->domain(fn () => app(CancelShiftUseCase::class)->execute($shift, $validated['reason'] ?? null));
    }

    public function bulkCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_template_id' => 'required|integer',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'work_location_id' => 'nullable|integer',
        ]);

        return $this->domain(fn () => app(BulkCreateShiftsUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                scheduleTemplateId: $validated['schedule_template_id'],
                employeeIds: $validated['employee_ids'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                workLocationId: $validated['work_location_id'] ?? null,
            ), 201);
    }
}
