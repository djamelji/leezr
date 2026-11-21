<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\Shift;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — multi-shift and reversible planning operations split out of ShiftController : undo / redo (ADR-722), copies (ADR-721), bulk template (ADR-720), week copy, duplicate. Same routes, permissions and payloads.
 */
class ShiftOperationController extends Controller
{
    use \App\Modules\Workforce\Http\Concerns\PlanningScope;
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals;

    /** ADR-722 — ANNULER une opération réversible (commande inverse serveur). */
    public function undoOperation(Request $request, int $id): JsonResponse
    {
        return $this->runUndoRedo($request, $id, 'undo');
    }

    /** ADR-722 — RÉTABLIR une opération (réexécution de l'intention métier avec contrôles actuels). */
    public function redoOperation(Request $request, int $id): JsonResponse
    {
        return $this->runUndoRedo($request, $id, 'redo');
    }

    private function runUndoRedo(Request $request, int $id, string $mode): JsonResponse
    {
        $validated = $request->validate([
            'overstaffing_ack' => 'nullable|string', 'overstaffing_reason' => 'nullable|string|max:500',
        ]);
        $op = \App\Core\Workforce\PlanningOperation::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        $uc = app(\App\Modules\Workforce\UseCases\PlanningUndoRedoUseCase::class);

        try {
            $res = $mode === 'undo'
                ? $uc->undo($op, (int) $request->user()->id, $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null)
                : $uc->redo($op, (int) $request->user()->id, $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null);

            return response()->json($res);
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            return response()->json($e->toPayload(), 422);
        } catch (\DomainException $e) {
            // Changement concurrent → 409 (pas d'écrasement) ; sinon 422.
            $code = str_starts_with($e->getMessage(), 'CONCURRENT_CHANGE') ? 409 : 422;

            return response()->json(['message' => $e->getMessage(), 'code' => $code === 409 ? 'concurrent_change' : 'undo_failed'], $code);
        }
    }

    /** ADR-721 — prévisualisation d'une copie de shifts (menu/journée) : opérations explicites source→cible. */
    public function copyPreview(Request $request): JsonResponse
    {
        $validated = $this->validateCopyOps($request);

        return response()->json(app(\App\Modules\Workforce\UseCases\CopyShiftsUseCase::class)->preview(
            $request->attributes->get('company_id'), $validated['operations'], $this->planningScope($request),
        ));
    }

    /** ADR-721 — commit atomique d'une copie de shifts (brouillons) ; sureffactif cumulatif confirmé. */
    public function copy(Request $request): JsonResponse
    {
        $validated = $this->validateCopyOps($request, withAck: true);

        return $this->runCopy($request, $validated['operations'], $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null, 'copy', ['operations' => $validated['operations']]);
    }

    /** ADR-721 — copie de SEMAINE : construit les opérations (décalage jour respectant le 1er jour Company). */
    public function copyWeekPreview(Request $request): JsonResponse
    {
        [$ops] = $this->buildWeekOps($request);

        return response()->json(app(\App\Modules\Workforce\UseCases\CopyShiftsUseCase::class)->preview(
            $request->attributes->get('company_id'), $ops, $this->planningScope($request),
        ));
    }

    public function copyWeekApply(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from_week_start' => 'required|date', 'to_week_start' => 'required|date',
            'employee_ids' => 'nullable|array', 'employee_ids.*' => 'integer',
            'department_id' => 'nullable|integer', 'work_location_id' => 'nullable|integer',
            'overstaffing_ack' => 'nullable|string', 'overstaffing_reason' => 'nullable|string|max:500',
        ]);
        [$ops] = $this->buildWeekOps($request);
        $intent = collect($v)->only(['from_week_start', 'to_week_start', 'employee_ids', 'department_id', 'work_location_id'])->all();

        return $this->runCopy($request, $ops, $v['overstaffing_ack'] ?? null, $v['overstaffing_reason'] ?? null, 'copy_week', $intent);
    }

    private function validateCopyOps(Request $request, bool $withAck = false): array
    {
        $rules = [
            'operations' => 'required|array|min:1|max:500',
            'operations.*.source_shift_id' => 'required|integer',
            'operations.*.target_employee_id' => 'nullable|integer',
            'operations.*.target_date' => 'required|date',
        ];
        if ($withAck) {
            $rules['overstaffing_ack'] = 'nullable|string';
            $rules['overstaffing_reason'] = 'nullable|string|max:500';
        }

        return $request->validate($rules);
    }

    private function runCopy(Request $request, array $ops, ?string $ack, ?string $reason, string $type = 'copy', array $intent = []): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        // ADR-722 — opération réversible (intention = requête d'origine pour le rétablissement).
        return $this->planningDomain(fn () => app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->attachCreated(
            app(\App\Modules\Workforce\UseCases\CopyShiftsUseCase::class)->commit($companyId, $ops, $this->planningScope($request), $ack, $reason, $request->user()?->id),
            $companyId, $request->user()?->id, $type, $intent ?: ['operations' => $ops],
        ), 201);
    }

    /**
     * Construit les opérations de copie de semaine : chaque shift (notCancelled) des salariés choisis dans
     * la semaine source → même salarié, date + delta (jours) vers la semaine cible. Respecte le 1er jour de
     * semaine Company (positions relatives conservées). Ne copie pas les publiés de la cible (jamais d'écrasement).
     *
     * @return array{0: array<int,array<string,mixed>>}
     */
    private function buildWeekOps(Request $request): array
    {
        $request->validate([
            'from_week_start' => 'required|date', 'to_week_start' => 'required|date',
            'employee_ids' => 'nullable|array', 'employee_ids.*' => 'integer',
            'department_id' => 'nullable|integer', 'work_location_id' => 'nullable|integer',
        ]);
        $ops = app(\App\Modules\Workforce\UseCases\CopyShiftsUseCase::class)->weekOperations( // ADR-848
            $request->attributes->get('company_id'), $request->input('from_week_start'), $request->input('to_week_start'),
            $request->input('employee_ids'), $request->filled('department_id') ? (int) $request->input('department_id') : null,
            $request->filled('work_location_id') ? (int) $request->input('work_location_id') : null,
        );

        return [$ops];
    }

    /** ADR-720 — prévisualisation d'une application groupée de modèle (classification + sureffectif cumulatif). */
    public function bulkApplyTemplatePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_template_id' => 'required|integer',
            'cells' => 'required|array|min:1|max:500',
            'cells.*.employee_id' => 'required|integer',
            'cells.*.date' => 'required|date',
        ]);

        return response()->json(app(\App\Modules\Workforce\UseCases\BulkApplyTemplateUseCase::class)->preview(
            $request->attributes->get('company_id'),
            (int) $validated['schedule_template_id'],
            $validated['cells'],
            $this->planningScope($request),
        ));
    }

    /** ADR-720 — application groupée atomique d'un modèle (brouillons) ; sureffactif cumulatif confirmé. */
    public function bulkApplyTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_template_id' => 'required|integer',
            'cells' => 'required|array|min:1|max:500',
            'cells.*.employee_id' => 'required|integer',
            'cells.*.date' => 'required|date',
            'overstaffing_ack' => 'nullable|string',
            'overstaffing_reason' => 'nullable|string|max:500',
        ]);

        $companyId = $request->attributes->get('company_id');

        return $this->planningDomain(fn () => app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->attachCreated(
            app(\App\Modules\Workforce\UseCases\BulkApplyTemplateUseCase::class)->commit(
                $companyId, (int) $validated['schedule_template_id'], $validated['cells'], $this->planningScope($request),
                $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null, $request->user()?->id,
            ),
            $companyId, $request->user()?->id, 'bulk_template', ['schedule_template_id' => (int) $validated['schedule_template_id'], 'cells' => $validated['cells']],
        ), 201);
    }

    /** SP1a.1 §3 — copie d'une semaine vers une autre (nouveaux brouillons, sans activités ni statut publié). */
    public function copyWeek(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_week_start' => 'required|date',
            'to_week_start' => 'required|date',
        ]);

        return $this->domain(fn () => app(\App\Modules\Workforce\UseCases\CopyWeekUseCase::class)->execute(
            $request->attributes->get('company_id'), $validated['from_week_start'], $validated['to_week_start'],
        ));
    }

    /** SP1a.1 §3 — duplique un shift en nouveau brouillon (date/employé cible). Scope manager sur la cible. */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_date' => 'nullable|date',
            'target_employee_id' => 'nullable|integer',
        ]);

        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);

        $targetEmployee = $validated['target_employee_id'] ?? $shift->employee_id;
        if ($deny = $this->guardShiftScope($request, (int) $targetEmployee)) {
            return $deny;
        }

        try {
            $new = app(\App\Modules\Workforce\UseCases\DuplicateShiftUseCase::class)->execute(
                $shift,
                $validated['target_date'] ?? null,
                $validated['target_employee_id'] ?? null,
            );
            $op = app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->recordCreated(
                $shift->company_id, $request->user()?->id, 'duplicate', [$new->id],
                ['source_shift_id' => $shift->id, 'target_date' => $validated['target_date'] ?? null, 'target_employee_id' => $validated['target_employee_id'] ?? null],
            );

            return response()->json($new->toArray() + ['operation_id' => $op->id], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
