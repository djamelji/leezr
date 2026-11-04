<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\CreateScheduledActivityUseCase;
use App\Modules\Workforce\UseCases\UpdateScheduledActivityUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Activités planifiées (ScheduledActivity) — Smart Planning SP1b (ADR-705 v2). Toujours scopé company +
 * scope manager (un planificateur n'agit que sur les collaborateurs visibles). Écritures via UseCases.
 */
class ScheduledActivityController extends Controller
{
    /** Liste par shift (?shift_id=) ou par salarié+jour (?employee_id=&date=). */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $q = ScheduledActivity::withoutGlobalScopes()->where('company_id', $companyId)->with('workLocation');

        if ($request->filled('shift_id')) {
            $q->where('shift_id', (int) $request->query('shift_id'));
        }
        if ($request->filled('employee_id')) {
            $q->where('employee_id', (int) $request->query('employee_id'));
        }
        if ($request->filled('date')) {
            $q->whereDate('start_at', $request->query('date'));
        }

        return response()->json($q->orderBy('start_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'activity_type' => 'nullable|string|max:60',
            'label' => 'nullable|string|max:120',
            'work_location_id' => 'nullable|integer',
            'schedule_template_id' => 'nullable|integer',
            'priority' => 'nullable|in:low,normal,high,critical',
            'notes' => 'nullable|string|max:500',
            'non_exclusive' => 'nullable|boolean',
        ]);

        $companyId = $request->attributes->get('company_id');
        $shift = Shift::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($validated['shift_id']);
        if ($deny = $this->guardScope($request, $shift->employee_id)) {
            return $deny;
        }

        try {
            $result = app(CreateScheduledActivityUseCase::class)->execute($companyId, (int) $validated['shift_id'], $validated);

            return response()->json(['activity' => $result['activity'], 'warnings' => $result['warnings']], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activity = ScheduledActivity::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);

        $validated = $request->validate([
            'shift_id' => 'nullable|integer',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'activity_type' => 'nullable|string|max:60',
            'label' => 'nullable|string|max:120',
            'work_location_id' => 'nullable|integer',
            'priority' => 'nullable|in:low,normal,high,critical',
            'notes' => 'nullable|string|max:500',
            'non_exclusive' => 'nullable|boolean',
            'expected_updated_at' => 'nullable|date',
        ]);

        // Verrou optimiste (concurrence entre planificateurs).
        if (! empty($validated['expected_updated_at'])
            && Carbon::parse($validated['expected_updated_at'])->ne($activity->updated_at)) {
            return response()->json([
                'message' => 'Cette activité a été modifiée entre-temps. Rechargez puis réessayez.',
                'code' => 'stale_version',
                'current_updated_at' => $activity->updated_at?->toIso8601String(),
            ], 409);
        }
        unset($validated['expected_updated_at']);

        // Scope sur l'employé source ET, en cas de réaffectation, sur l'employé cible.
        if ($deny = $this->guardScope($request, $activity->employee_id)) {
            return $deny;
        }
        if (! empty($validated['shift_id'])) {
            $target = Shift::withoutGlobalScopes()->where('company_id', $activity->company_id)->findOrFail($validated['shift_id']);
            if ($deny = $this->guardScope($request, $target->employee_id)) {
                return $deny;
            }
        }

        try {
            $result = app(UpdateScheduledActivityUseCase::class)->execute($activity, $validated);

            return response()->json(['activity' => $result['activity'], 'warnings' => $result['warnings']]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Annule une activité (shift publié) : soft, sans toucher le Shift ni le TimeEntry. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $activity = ScheduledActivity::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        if ($deny = $this->guardScope($request, $activity->employee_id)) {
            return $deny;
        }

        $activity->update(['status' => ScheduledActivity::STATUS_CANCELLED]);

        return response()->json($activity->fresh());
    }

    /** Suppression dure réservée aux activités d'un shift brouillon ; sinon annuler. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $activity = ScheduledActivity::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->with('shift')->findOrFail($id);
        if ($deny = $this->guardScope($request, $activity->employee_id)) {
            return $deny;
        }

        if ($activity->shift && $activity->shift->status !== Shift::STATUS_DRAFT) {
            return response()->json(['message' => 'Only activities of a draft shift can be deleted; cancel instead.'], 422);
        }

        $activity->delete();

        return response()->json(['message' => 'Activity deleted.']);
    }

    /** Duplique une activité (par défaut juste après l'originale ; sinon start_at explicite). */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['start_at' => 'nullable|date']);
        $activity = ScheduledActivity::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        if ($deny = $this->guardScope($request, $activity->employee_id)) {
            return $deny;
        }

        $duration = max(1, (int) $activity->start_at->diffInMinutes($activity->end_at));
        $start = ! empty($validated['start_at']) ? Carbon::parse($validated['start_at']) : $activity->end_at->copy();
        $end = $start->copy()->addMinutes($duration);

        try {
            $result = app(CreateScheduledActivityUseCase::class)->execute($activity->company_id, $activity->shift_id, [
                'start_at' => $start, 'end_at' => $end,
                'activity_type' => $activity->activity_type, 'label' => $activity->label,
                'work_location_id' => $activity->work_location_id, 'priority' => $activity->priority,
                'notes' => $activity->notes, 'source' => 'manual',
            ]);

            return response()->json(['activity' => $result['activity'], 'warnings' => $result['warnings']], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Scope manager : null si hors périmètre → 403. */
    private function guardScope(Request $request, int $employeeId): ?JsonResponse
    {
        $viewAll = WorkforceAccess::hasViewAll($request, 'employee')
            || ($request->user()?->hasCompanyPermission($request->attributes->get('company'), 'workforce.planning_publish') ?? false);
        if ($viewAll) {
            return null;
        }
        $visible = WorkforceAccess::visibleEmployeeIds($request, 'employee');
        if ($visible !== null && ! in_array($employeeId, $visible, true)) {
            return response()->json(['message' => 'Collaborateur hors de votre périmètre de gestion.'], 403);
        }

        return null;
    }
}
