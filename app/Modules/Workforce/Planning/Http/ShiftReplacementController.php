<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\Shift;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\PlanningBoardReadModel;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — replacement of a shift impacted by an absence (ADR-717) split out of ShiftController : candidates and replacement. Same routes, permissions and payloads.
 */
class ShiftReplacementController extends Controller
{
    use \App\Modules\Workforce\Http\Concerns\PlanningScope;
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals;

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
}
