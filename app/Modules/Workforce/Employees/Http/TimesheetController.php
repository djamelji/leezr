<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\TimesheetPeriod;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\TimesheetAnomalyReadModel;
use App\Modules\Workforce\ReadModels\TimesheetHistoryReadModel;
use App\Modules\Workforce\ReadModels\TimesheetLineReadModel;
use App\Modules\Workforce\ReadModels\TimesheetPeriodReadModel;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\UseCases\ApproveTimesheetUseCase;
use App\Modules\Workforce\UseCases\GenerateTimesheetUseCase;
use App\Modules\Workforce\UseCases\LockTimesheetUseCase;
use App\Modules\Workforce\UseCases\RejectTimesheetUseCase;
use App\Modules\Workforce\UseCases\ReopenTimesheetUseCase;
use App\Modules\Workforce\UseCases\SubmitTimesheetUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    use \App\Modules\Workforce\Http\Concerns\RespondsToDomainRefusals; // ADR-848

    /**
     * B3-checkpoint (ADR-699) — PÉRIMÈTRE (domaine 'time'), réutilise WorkforceAccess (comme les pointages).
     * null = vue globale si l'utilisateur a view-all ('time' ⇐ timesheet_approve OU time_manage) ; sinon
     * restreint à soi + rapports directs. Permission ≠ périmètre ≠ état : les trois sont contrôlés serveur.
     */
    private function timeScope(Request $request): ?array
    {
        return WorkforceAccess::scopeIdsOrNull($request, 'time');
    }

    /** Garde de périmètre par collaborateur (lecture/soumission/réouverture/verrouillage) → 403 si hors périmètre. */
    private function guardEmployee(Request $request, int $employeeId): void
    {
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employeeId, 'time'), 403, 'Feuille de temps hors de votre périmètre.');
    }

    /** Garde d'APPROBATION/REFUS : Company (timesheet_approve) OU équipe (timesheet_approve_team, rapports directs, pas soi). */
    private function guardApprove(Request $request, int $employeeId): void
    {
        abort_unless(WorkforceAccess::canApproveTimesheet($request, $employeeId), 403, 'Approbation hors de votre périmètre.');
    }

    /** ADR-848 : a period of the company (tenant-explicit) — was repeated in every action. */
    private function period(int $companyId, int $id): TimesheetPeriod
    {
        return TimesheetPeriod::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $timesheets = TimesheetPeriodReadModel::forCompany(
            companyId: $companyId,
            periodStart: $request->input('period_start'),
            periodEnd: $request->input('period_end'),
            status: $request->input('status'),
            perPage: (int) $request->input('per_page', 15),
            visibleIds: $this->timeScope($request),
        );

        return response()->json($timesheets);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = $this->period($companyId, $id)->load('employee:id,first_name,last_name');
        $this->guardEmployee($request, $period->employee_id);

        $lines = TimesheetLineReadModel::forPeriod($period->id);
        $anomalies = TimesheetAnomalyReadModel::forPeriod($period->id);

        return response()->json([
            'period' => $period,
            'lines' => $lines,
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * E3-B/B3 — historique métier scopé (lecture seule) : événements de workflow de la période
     * (soumission/approbation/refus/réouverture/verrouillage) + corrections de pointage tracées
     * (qui/quand/quoi/pourquoi). Projection assainie des événements d'audit DÉJÀ écrits — aucun moteur
     * d'audit supplémentaire. Scopé par la période (company + salarié), gated `workforce.timesheet_view`.
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = $this->period($companyId, $id);
        // L'historique de correction est une donnée RH soumise au périmètre d'accès.
        $this->guardEmployee($request, $period->employee_id);

        return response()->json([
            'events' => TimesheetHistoryReadModel::forPeriod($companyId, $period),
        ]);
    }

    /**
     * P1a (ADR-703) — CONTRÔLE RÉGLEMENTAIRE sur le temps constaté (projection de CONSULTATION).
     * Lecture seule : ne mute PAS la feuille, ne bloque rien, ne modifie pas anomaly_count (P0 préservé).
     * Résultats pass/warning/violation/not_verifiable/not_applicable — statut autorité serveur.
     * Scopé (company + périmètre), gated `workforce.timesheet_view`.
     */
    public function compliance(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $period = $this->period($companyId, $id);
        $this->guardEmployee($request, $period->employee_id);

        $checker = app(\App\Modules\Workforce\Services\WorkTimeComplianceChecker::class);
        $results = $checker->check($period);

        return response()->json([
            'results' => $results,
            'summary' => $checker->summarize($results),
            // Projection de consultation calculée MAINTENANT — pas une décision réglementaire historisée.
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);
        $this->guardEmployee($request, (int) $validated['employee_id']);

        return $this->domain(fn () => app(GenerateTimesheetUseCase::class)->execute(
            companyId: $companyId,
            employeeId: $validated['employee_id'],
            periodStart: $validated['period_start'],
            periodEnd: $validated['period_end'],
        ), 201);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = $this->period($companyId, $id);
        $this->guardEmployee($request, $period->employee_id);

        return $this->domain(fn () => app(SubmitTimesheetUseCase::class)->execute(
            period: $period,
            submittedBy: $request->user()->id,
        ));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'approval_note' => 'nullable|string|max:1000',
        ]);

        $period = $this->period($companyId, $id);
        $this->guardApprove($request, $period->employee_id);

        return $this->domain(fn () => app(ApproveTimesheetUseCase::class)->execute(
            period: $period,
            approvedBy: $request->user()->id,
            approvalNote: $validated['approval_note'] ?? null,
        ));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $period = $this->period($companyId, $id);
        $this->guardApprove($request, $period->employee_id);

        return $this->domain(fn () => app(RejectTimesheetUseCase::class)->execute(
            period: $period,
            rejectedBy: $request->user()->id,
            rejectionReason: $validated['rejection_reason'],
        ));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = $this->period($companyId, $id);
        $this->guardEmployee($request, $period->employee_id);

        return $this->domain(fn () => app(ReopenTimesheetUseCase::class)->execute(
            period: $period,
        ));
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        $period = $this->period($companyId, $id);
        $this->guardEmployee($request, $period->employee_id);

        return $this->domain(fn () => app(LockTimesheetUseCase::class)->execute(
            period: $period,
            lockedBy: $request->user()->id,
        ));
    }

    public function statistics(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        // ANO-006: the time page loads this before the user picks a period. Make the
        // period optional and default to the current month so the stats widget loads
        // (previously 422'd on every initial load). Explicit range still honored.
        $validated = $request->validate([
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
        ]);

        $periodStart = $validated['period_start'] ?? now()->startOfMonth()->toDateString();
        $periodEnd = $validated['period_end'] ?? now()->endOfMonth()->toDateString();

        $stats = TimesheetPeriodReadModel::statistics(
            companyId: $companyId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            visibleIds: $this->timeScope($request),
        );

        return response()->json($stats);
    }
}
