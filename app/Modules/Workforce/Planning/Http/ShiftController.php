<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\Shift;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\PlanningBoardReadModel;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Support\WeekStartResolver;
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
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $request->validate([
            'employee_id' => 'nullable|integer',
            'status' => 'nullable|string|in:draft,published,completed,cancelled',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(ShiftReadModel::forEmployee(
            companyId: $companyId,
            employeeId: $request->query('employee_id') ? (int) $request->query('employee_id') : 0,
            from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
            to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
            status: $request->query('status'),
            perPage: (int) $request->query('per_page', 15),
        ));
    }

    public function week(Request $request): JsonResponse
    {
        $request->validate(['date' => 'required|date']);

        // E3-B/B2 — début de semaine = préférence Company (0=dim..6=sam) résolue par WeekStartResolver,
        // et NON le lundi codé en dur de startOfWeek(). Cohérent avec la grille desktop et l'agenda mobile.
        $company = $request->attributes->get('company');
        $firstDay = $company ? WeekStartResolver::resolve($company) : Carbon::MONDAY;

        return response()->json(ShiftReadModel::forWeek(
            companyId: $request->attributes->get('company_id'),
            weekStart: Carbon::parse($request->query('date'))->startOfWeek($firstDay),
        ));
    }

    public function calendar(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(ShiftReadModel::calendar(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
        ));
    }

    /**
     * W-G4A — tableau Planning : shifts + absences (W-G3) + chevauchements, scopé + filtré serveur.
     */
    public function board(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'department_id' => 'sometimes|integer',
            'employee_id' => 'sometimes|integer',
            'location_id' => 'sometimes|integer',
            'status' => 'sometimes|string',
        ]);

        return response()->json(PlanningBoardReadModel::board(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
            visibleIds: $this->planningScope($request),
            filters: [
                'department_id' => $request->query('department_id'),
                'employee_id' => $request->query('employee_id'),
                'location_id' => $request->query('location_id'),
                'status' => $request->query('status'),
            ],
        ));
    }

    /**
     * W-G4B — suggestions de collaborateurs disponibles pour une (ré)affectation. Scopé + borné.
     */
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'exclude_shift_id' => 'sometimes|integer',
            'job_role_id' => 'sometimes|integer',
        ]);

        return response()->json(PlanningBoardReadModel::availability(
            companyId: $request->attributes->get('company_id'),
            start: Carbon::parse($request->query('start')),
            end: Carbon::parse($request->query('end')),
            excludeShiftId: $request->filled('exclude_shift_id') ? (int) $request->query('exclude_shift_id') : null,
            jobRoleId: $request->filled('job_role_id') ? (int) $request->query('job_role_id') : null,
            visibleIds: $this->planningScope($request),
        ));
    }

    /**
     * W-G4A — comparaison prévu (shifts) / réalisé (pointages), bornée + scopée. Informatif.
     */
    public function comparison(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'employee_id' => 'sometimes|integer',
        ]);

        return response()->json(PlanningBoardReadModel::comparison(
            companyId: $request->attributes->get('company_id'),
            from: Carbon::parse($request->query('from')),
            to: Carbon::parse($request->query('to')),
            visibleIds: $this->planningScope($request),
            employeeId: $request->filled('employee_id') ? (int) $request->query('employee_id') : null,
        ));
    }

    /**
     * Scope planning : vue GLOBALE si RH (workforce.view+manage) ou publication (planning_publish) ;
     * `planning_manage` SEUL = manager d'équipe → scopé à soi + rapports directs. Ainsi un manager ne
     * peut (ré)affecter/voir que son périmètre, un RH/publieur gère toute l'entreprise (W-G4B §8).
     */
    private function planningScope(Request $request): ?array
    {
        $viewAll = WorkforceAccess::hasViewAll($request, 'employee')
            || ($request->user()?->hasCompanyPermission($request->attributes->get('company'), 'workforce.planning_publish') ?? false);

        return $viewAll ? null : WorkforceAccess::visibleEmployeeIds($request, 'employee');
    }

    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return response()->json(ShiftReadModel::statistics(
            companyId: $request->attributes->get('company_id'),
            from: $request->query('from') ? Carbon::parse($request->query('from')) : null,
            to: $request->query('to') ? Carbon::parse($request->query('to')) : null,
        ));
    }

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
        ]);

        try {
            return response()->json(app(CreateShiftUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
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
            ), 201);
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            return response()->json($e->toPayload(), 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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

        return $this->runCopy($request, $validated['operations'], $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null);
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

        return $this->runCopy($request, $ops, $v['overstaffing_ack'] ?? null, $v['overstaffing_reason'] ?? null);
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

    private function runCopy(Request $request, array $ops, ?string $ack, ?string $reason): JsonResponse
    {
        try {
            return response()->json(app(\App\Modules\Workforce\UseCases\CopyShiftsUseCase::class)->commit(
                $request->attributes->get('company_id'), $ops, $this->planningScope($request), $ack, $reason, $request->user()?->id,
            ), 201);
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            return response()->json($e->toPayload(), 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
        $companyId = $request->attributes->get('company_id');
        $from = Carbon::parse($request->input('from_week_start'))->startOfDay();
        $to = Carbon::parse($request->input('to_week_start'))->startOfDay();
        $delta = $from->diffInDays($to, false); // positions relatives conservées quel que soit le 1er jour

        $q = Shift::withoutGlobalScopes()->where('company_id', $companyId)->notCancelled()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $from->copy()->addDays(6)->toDateString());
        if ($request->filled('employee_ids')) {
            $q->whereIn('employee_id', $request->input('employee_ids'));
        }
        if ($request->filled('work_location_id')) {
            $q->where('work_location_id', (int) $request->input('work_location_id'));
        }
        if ($request->filled('department_id')) {
            $deptEmp = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('department_id', (int) $request->input('department_id'))->pluck('id');
            $q->whereIn('employee_id', $deptEmp);
        }

        $ops = $q->get()->map(fn (Shift $s) => [
            'source_shift_id' => $s->id,
            'target_employee_id' => $s->employee_id,
            'target_date' => Carbon::parse($s->date->toDateString())->addDays($delta)->toDateString(),
        ])->all();

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

        try {
            $result = app(\App\Modules\Workforce\UseCases\BulkApplyTemplateUseCase::class)->commit(
                $request->attributes->get('company_id'),
                (int) $validated['schedule_template_id'],
                $validated['cells'],
                $this->planningScope($request),
                $validated['overstaffing_ack'] ?? null,
                $validated['overstaffing_reason'] ?? null,
                $request->user()?->id,
            );

            return response()->json($result, 201);
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
            return response()->json(app(UpdateShiftUseCase::class)->execute($shift, $validated, $ovAck, $ovReason, $request->user()?->id));
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

    /** SP1a.1 §3 — copie d'une semaine vers une autre (nouveaux brouillons, sans activités ni statut publié). */
    public function copyWeek(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_week_start' => 'required|date',
            'to_week_start' => 'required|date',
        ]);

        try {
            $result = app(\App\Modules\Workforce\UseCases\CopyWeekUseCase::class)->execute(
                $request->attributes->get('company_id'),
                $validated['from_week_start'],
                $validated['to_week_start'],
            );

            return response()->json($result);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
        $visible = $this->planningScope($request);
        if ($visible !== null && ! in_array((int) $targetEmployee, $visible, true)) {
            return response()->json(['message' => 'Collaborateur hors de votre périmètre de gestion.'], 403);
        }

        try {
            $new = app(\App\Modules\Workforce\UseCases\DuplicateShiftUseCase::class)->execute(
                $shift,
                $validated['target_date'] ?? null,
                $validated['target_employee_id'] ?? null,
            );

            return response()->json($new, 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function publish(Request $request): JsonResponse
    {
        $validated = $request->validate(['shift_ids' => 'required|array|min:1', 'shift_ids.*' => 'integer']);

        try {
            $shifts = app(PublishShiftsUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                shiftIds: $validated['shift_ids'],
            );

            // ADR-717 — l'intention a été enregistrée dans la transaction (outbox) ; flush post-commit
            // immédiat (la commande planifiée rattrape tout échec ou crash post-commit). Idempotent.
            app(\App\Modules\Workforce\Services\PlanningOutbox::class)->flush();

            return response()->json($shifts);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * ADR-717 — candidats au remplacement d'un shift publié impacté par une absence.
     * Calcule le(s) créneau(x) NON ASSURÉ(s) (intersection shift × fenêtre d'absence confirmée du
     * titulaire) puis réutilise la lecture de disponibilité (scopée, absents exclus, occupés marqués,
     * contrat vérifié). La catégorie de couverture (jobRole indicatif) sert d'indice, jamais de filtre dur.
     */
    public function replacementCandidates(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $shift = Shift::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);
        if ($deny = $this->guardShiftScope($request, $shift->employee_id)) {
            return $deny;
        }

        // Fenêtres non assurées = intersection du shift avec les absences confirmées du titulaire ce jour.
        $leaves = \App\Core\Workforce\LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $shift->employee_id)
            ->whereIn('status', [\App\Core\Workforce\LeaveRequest::STATUS_APPROVED, \App\Core\Workforce\LeaveRequest::STATUS_CONSUMED])
            ->whereDate('date_from', '<=', $shift->date->toDateString())
            ->whereDate('date_to', '>=', $shift->date->toDateString())
            ->get();

        $gaps = [];
        foreach ($leaves as $l) {
            if ($w = $l->absentWindowOn($shift->start_at->copy()->startOfDay())) {
                $gapStart = $w[0]->greaterThan($shift->start_at) ? $w[0] : $shift->start_at;
                $gapEnd = $w[1]->lessThan($shift->end_at) ? $w[1] : $shift->end_at;
                if ($gapStart->lt($gapEnd)) {
                    $gaps[] = ['start' => $gapStart->toIso8601String(), 'end' => $gapEnd->toIso8601String()];
                }
            }
        }
        // À défaut d'absence (ex. appel manuel), la fenêtre = le shift entier.
        if (! $gaps) {
            $gaps[] = ['start' => $shift->start_at->toIso8601String(), 'end' => $shift->end_at->toIso8601String()];
        }

        $window = ['start' => $gaps[0]['start'], 'end' => end($gaps)['end']];
        $categoryId = $shift->template_snapshot['coverage_category_id'] ?? null;

        $candidates = PlanningBoardReadModel::availability(
            companyId: $companyId,
            start: Carbon::parse($window['start']),
            end: Carbon::parse($window['end']),
            excludeShiftId: $shift->id,
            visibleIds: $this->planningScope($request),
        );

        return response()->json([
            'shift_id' => $shift->id,
            'original_employee_id' => $shift->employee_id,
            'coverage_category_id' => $categoryId,
            'unassured' => $gaps,
            'window' => $window,
            'candidates' => $candidates,
        ]);
    }

    /** ADR-717 — crée + publie le shift de remplacement (UseCases existants) puis notifie le remplaçant. */
    public function replace(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
        ]);

        $shift = Shift::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);
        if ($deny = $this->guardShiftScope($request, $shift->employee_id)) {
            return $deny;
        }
        if ($deny = $this->guardShiftScope($request, (int) $validated['employee_id'])) {
            return $deny;
        }

        try {
            $replacement = app(\App\Modules\Workforce\UseCases\CreateReplacementShiftUseCase::class)->execute(
                companyId: $companyId,
                originalShiftId: $shift->id,
                replacementEmployeeId: (int) $validated['employee_id'],
                startAt: $validated['start_at'],
                endAt: $validated['end_at'],
                reviewerId: $request->user()?->id,
            );

            // Le remplacement publié a enregistré son intention dans l'outbox (via PublishShiftsUseCase) ;
            // flush post-commit → notifie le remplaçant (réutilise PlanningNotifier — pas de 2ᵉ moteur).
            app(\App\Modules\Workforce\Services\PlanningOutbox::class)->flush();

            return response()->json(['replacement' => $replacement], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Scope manager sur un employé (null = autorisé). */
    private function guardShiftScope(Request $request, int $employeeId): ?JsonResponse
    {
        $visible = $this->planningScope($request);
        if ($visible !== null && ! in_array($employeeId, $visible, true)) {
            return response()->json(['message' => 'Collaborateur hors de votre périmètre de gestion.'], 403);
        }

        return null;
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $shift = Shift::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            return response()->json(app(CancelShiftUseCase::class)->execute($shift, $validated['reason'] ?? null));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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

        try {
            return response()->json(app(BulkCreateShiftsUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                scheduleTemplateId: $validated['schedule_template_id'],
                employeeIds: $validated['employee_ids'],
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                workLocationId: $validated['work_location_id'] ?? null,
            ), 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
