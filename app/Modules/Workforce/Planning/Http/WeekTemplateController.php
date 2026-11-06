<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\ApplyWeekTemplateUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Semaines types (WeekTemplate) — Refonte Smart Planning M3 (ADR-726).
 * CRUD (lignes imbriquées) + prévisualisation + application + capture d'une semaine existante.
 * Lecture planning_view, écriture planning_manage. Manager scope sur preview/apply/capture.
 */
class WeekTemplateController extends Controller
{
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
        if ($err = $this->linesError($companyId, $data['lines'] ?? [])) {
            return response()->json(['message' => $err], 422);
        }

        $wt = DB::transaction(function () use ($companyId, $data) {
            $wt = WeekTemplate::create([
                'company_id' => $companyId, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'strategy' => $data['strategy'] ?? 'nominative', 'scope' => $data['scope'] ?? 'company',
                'department_id' => $data['department_id'] ?? null, 'work_location_id' => $data['work_location_id'] ?? null,
                'enabled' => $data['enabled'] ?? true,
            ]);
            $this->syncLines($wt, $data['lines'] ?? []);

            return $wt;
        });

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
        if (array_key_exists('lines', $data) && ($err = $this->linesError($companyId, $data['lines'] ?? []))) {
            return response()->json(['message' => $err], 422);
        }

        DB::transaction(function () use ($wt, $data) {
            $wt->fill(collect($data)->only(['name', 'description', 'strategy', 'scope', 'department_id', 'work_location_id', 'enabled'])->toArray())->save();
            if (array_key_exists('lines', $data)) {
                $wt->lines()->delete();
                $this->syncLines($wt, $data['lines'] ?? []);
            }
        });

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
        try {
            return response()->json(app(ApplyWeekTemplateUseCase::class)->preview(
                $request->attributes->get('company_id'), $id, $request->input('target_date'), $this->planningScope($request),
            ));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
        try {
            $companyId = $request->attributes->get('company_id');
            $result = app(ApplyWeekTemplateUseCase::class)->commit(
                $companyId, $id, $validated['target_date'],
                $validated['assignments'] ?? [], $this->planningScope($request),
                $validated['overstaffing_ack'] ?? null, $validated['overstaffing_reason'] ?? null, $request->user()?->id,
            );
            // UNE opération réversible (apply_week_template) : undo = suppression atomique des brouillons créés.
            if (! empty($result['created'])) {
                $op = app(\App\Modules\Workforce\Services\PlanningOperationRecorder::class)->recordCreated(
                    $companyId, $request->user()?->id, 'apply_week_template', $result['created'],
                    ['week_template_id' => $id, 'target_date' => $validated['target_date'], 'assignments' => $validated['assignments'] ?? []],
                );
                $result['operation_id'] = $op->id;
            }

            return response()->json($result, 201);
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            return response()->json($e->toPayload(), 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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

        $wt = DB::transaction(function () use ($companyId, $validated, $weekStart, $shifts) {
            $wt = WeekTemplate::create([
                'company_id' => $companyId, 'name' => $validated['name'], 'strategy' => 'nominative', 'scope' => 'company', 'enabled' => true,
            ]);
            $order = 0;
            foreach ($shifts as $s) {
                $dayIndex = (int) $weekStart->diffInDays(\Illuminate\Support\Carbon::parse($s->date->toDateString()), false);
                if ($dayIndex < 0 || $dayIndex > 6) {
                    continue;
                }
                WeekTemplateLine::create([
                    'company_id' => $companyId, 'week_template_id' => $wt->id, 'day_index' => $dayIndex,
                    'schedule_template_id' => $s->schedule_template_id, 'kind' => 'nominative', 'employee_id' => $s->employee_id,
                    'work_location_id' => $s->work_location_id, 'sort_order' => $order++,
                ]);
            }

            return $wt;
        });

        return response()->json($wt->load('lines'), 201);
    }

    // ── helpers ──

    private function planningScope(Request $request): ?array
    {
        $viewAll = WorkforceAccess::hasViewAll($request, 'employee')
            || ($request->user()?->hasCompanyPermission($request->attributes->get('company'), 'workforce.planning_publish') ?? false);

        return $viewAll ? null : WorkforceAccess::visibleEmployeeIds($request, 'employee');
    }

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

    /** Valide l'appartenance company des références + la cohérence kind/salarié/effectif. */
    private function linesError(int $companyId, array $lines): ?string
    {
        foreach ($lines as $ln) {
            if (! ScheduleTemplate::withoutGlobalScopes()->where('id', $ln['schedule_template_id'])->where('company_id', $companyId)->exists()) {
                return 'A schedule template in a line does not belong to this company.';
            }
            if (($ln['kind'] ?? null) === 'nominative') {
                if (empty($ln['employee_id'])) {
                    return 'A nominative line requires an employee.';
                }
                if (! Employee::withoutGlobalScopes()->where('id', $ln['employee_id'])->where('company_id', $companyId)->exists()) {
                    return 'An employee in a line does not belong to this company.';
                }
            } elseif (($ln['kind'] ?? null) === 'needs' && empty($ln['headcount'])) {
                return 'A needs line requires a headcount.';
            }
        }

        return null;
    }

    private function syncLines(WeekTemplate $wt, array $lines): void
    {
        $order = 0;
        foreach ($lines as $ln) {
            WeekTemplateLine::create([
                'company_id' => $wt->company_id, 'week_template_id' => $wt->id,
                'day_index' => (int) $ln['day_index'], 'schedule_template_id' => (int) $ln['schedule_template_id'],
                'kind' => $ln['kind'], 'employee_id' => $ln['employee_id'] ?? null,
                'headcount' => $ln['kind'] === 'needs' ? (int) ($ln['headcount'] ?? 1) : null,
                'work_location_id' => $ln['work_location_id'] ?? null, 'department_id' => $ln['department_id'] ?? null,
                'coverage_category_id' => $ln['coverage_category_id'] ?? null, 'sort_order' => $ln['sort_order'] ?? $order++,
            ]);
        }
    }
}
