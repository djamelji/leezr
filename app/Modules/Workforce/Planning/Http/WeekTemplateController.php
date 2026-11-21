<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\UseCases\ApplyWeekTemplateUseCase;
use App\Modules\Workforce\UseCases\WeekTemplateEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Semaines types (WeekTemplate) — Refonte Smart Planning M3 (ADR-726).
 * CRUD (lignes imbriquées) + prévisualisation + application + capture d'une semaine existante.
 * Lecture planning_view, écriture planning_manage. Manager scope sur preview/apply/capture.
 */
class WeekTemplateController extends Controller
{
    use \App\Modules\Workforce\Http\Concerns\PlanningScope; // ADR-848 : same write perimeter as the shift controllers
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals;

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            WeekTemplate::withoutGlobalScopes()->where('company_id', $request->attributes->get('company_id'))
                ->with('lines')->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $companyId = $request->attributes->get('company_id');

        if (WeekTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('name', $data['name'])->exists()) {
            return response()->json(['message' => "A week template named '{$data['name']}' already exists."], 422);
        }
        if ($err = app(WeekTemplateEditor::class)->linesError($companyId, $data['lines'] ?? [])) {
            return response()->json(['message' => $err], 422);
        }

        $wt = app(WeekTemplateEditor::class)->create($companyId, $data); // ADR-848

        app(AuditLogger::class)->logCompany($companyId, 'week_template.created', 'week_template', (string) $wt->id, ['metadata' => ['category' => 'workforce.planning', 'name' => $wt->name]]);

        return response()->json($wt->load('lines'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $wt = WeekTemplate::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);
        $data = $this->validatePayload($request, false);

        if (isset($data['name']) && $data['name'] !== $wt->name
            && WeekTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('name', $data['name'])->where('id', '!=', $wt->id)->exists()) {
            return response()->json(['message' => 'Name already used.'], 422);
        }
        if (array_key_exists('lines', $data) && ($err = app(WeekTemplateEditor::class)->linesError($companyId, $data['lines'] ?? []))) {
            return response()->json(['message' => $err], 422);
        }

        app(WeekTemplateEditor::class)->update($wt, $data); // ADR-848

        app(AuditLogger::class)->logCompany($companyId, 'week_template.updated', 'week_template', (string) $wt->id, ['metadata' => ['category' => 'workforce.planning']]);

        return response()->json($wt->fresh()->load('lines'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $wt = WeekTemplate::withoutGlobalScopes()->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        $wt->delete(); // lignes en cascade ; aucune dépendance (copie par valeur à l'application)

        return response()->json(['message' => 'Week template deleted.']);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $request->validate(['target_date' => 'required|date']);
        return $this->domain(fn () => app(ApplyWeekTemplateUseCase::class)->preview(
                $request->attributes->get('company_id'), $id, $request->input('target_date'), $this->planningScope($request),
            ));
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_date' => 'required|date',
            'assignments' => 'nullable|array',
            'assignments.*.line_id' => 'required|integer',
            'assignments.*.employee_id' => 'required|integer',
            'overstaffing_ack' => 'nullable|string',
            'overstaffing_reason' => 'nullable|string|max:500',
        ]);
        $companyId = $request->attributes->get('company_id');

        // UNE opération réversible (apply_week_template) : undo = suppression atomique des brouillons créés.
        return $this->planningDomain(fn () => app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->attachCreated(
            app(ApplyWeekTemplateUseCase::class)->commit(
                $companyId, $id, $validated['target_date'], $validated['assignments'] ?? [], $this->planningScope($request),
                $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null, $request->user()?->id,
            ),
            $companyId, $request->user()?->id, 'apply_week_template', ['week_template_id' => $id, 'target_date' => $validated['target_date'], 'assignments' => $validated['assignments'] ?? []],
        ), 201);
    }

    /** M4 (ADR-727) — prévisualisation d'une RÉPÉTITION multi-semaines (cumulative, dry-run). */
    public function repeatPreview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'first_week_date' => 'required|date',
            'horizon' => 'required|in:'.implode(',', \App\Modules\Workforce\UseCases\RepeatWeekTemplateUseCase::HORIZONS),
            'assignments' => 'nullable|array',
            'assignments.*.week_index' => 'required|integer|min:0',
            'assignments.*.line_id' => 'required|integer',
            'assignments.*.employee_id' => 'required|integer',
        ]);
        return $this->domain(fn () => app(\App\Modules\Workforce\UseCases\RepeatWeekTemplateUseCase::class)->preview(
                $request->attributes->get('company_id'), $id, $validated['first_week_date'], $validated['horizon'],
                $validated['assignments'] ?? [], $this->planningScope($request), $request->user()?->id,
            ));
    }

    /** M4 (ADR-727) — application ATOMIQUE GLOBALE d'une répétition ; UNE opération réversible. */
    public function repeatApply(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'first_week_date' => 'required|date',
            'horizon' => 'required|in:'.implode(',', \App\Modules\Workforce\UseCases\RepeatWeekTemplateUseCase::HORIZONS),
            'assignments' => 'nullable|array',
            'assignments.*.week_index' => 'required|integer|min:0',
            'assignments.*.line_id' => 'required|integer',
            'assignments.*.employee_id' => 'required|integer',
            'overstaffing_ack' => 'nullable|string',
            'overstaffing_reason' => 'nullable|string|max:500',
        ]);
        $companyId = $request->attributes->get('company_id');

        // UNE intention utilisateur = UNE opération réversible (même si exécutée en plusieurs semaines).
        return $this->planningDomain(fn () => app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->attachCreated(
            app(\App\Modules\Workforce\UseCases\RepeatWeekTemplateUseCase::class)->commit(
                $companyId, $id, $validated['first_week_date'], $validated['horizon'], $validated['assignments'] ?? [], $this->planningScope($request),
                $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null, $request->user()?->id,
            ),
            $companyId, $request->user()?->id, 'repeat_week_template',
            ['week_template_id' => $id, 'first_week_date' => $validated['first_week_date'], 'horizon' => $validated['horizon'], 'assignments' => $validated['assignments'] ?? []],
        ), 201);
    }

    /** Capture une semaine existante en semaine type NOMINATIVE (références figées : jour, modèle, salarié). */
    public function capture(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:120', 'target_date' => 'required|date']);
        $companyId = $request->attributes->get('company_id');

        if (WeekTemplate::withoutGlobalScopes()->where('company_id', $companyId)->where('name', $validated['name'])->exists()) {
            return response()->json(['message' => 'A week template with this name already exists.'], 422);
        }

        $company = Company::findOrFail($companyId);
        $weekStart = app(ApplyWeekTemplateUseCase::class)->resolveWeekStart($company, $validated['target_date']);
        $visible = $this->planningScope($request);

        $q = Shift::withoutGlobalScopes()->where('company_id', $companyId)->notCancelled()
            ->whereNotNull('schedule_template_id')
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekStart->copy()->addDays(6)->toDateString());
        if ($visible !== null) {
            $q->whereIn('employee_id', $visible); // manager scope : ne capture que le périmètre autorisé
        }
        $shifts = $q->get();

        $wt = app(WeekTemplateEditor::class)->capture($companyId, $validated['name'], $weekStart, $shifts); // ADR-848

        return response()->json($wt->load('lines'), 201);
    }

    // ── helpers ──


    private function validatePayload(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ($creating ? 'required' : 'nullable').'|string|max:120',
            'description' => 'nullable|string|max:1000',
            'strategy' => 'nullable|in:'.implode(',', WeekTemplate::STRATEGIES),
            'scope' => 'nullable|in:'.implode(',', WeekTemplate::SCOPES),
            'department_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'lines' => ($creating ? 'required|array' : 'nullable|array'),
            'lines.*.day_index' => 'required|integer|min:0|max:6',
            'lines.*.schedule_template_id' => 'required|integer',
            'lines.*.kind' => 'required|in:'.implode(',', WeekTemplateLine::KINDS),
            'lines.*.employee_id' => 'nullable|integer',
            'lines.*.headcount' => 'nullable|integer|min:1|max:200',
            'lines.*.work_location_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            'lines.*.coverage_category_id' => 'nullable|integer',
            'lines.*.sort_order' => 'nullable|integer',
        ]);
    }
}
