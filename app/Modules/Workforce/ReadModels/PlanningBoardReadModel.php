<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;

/**
 * W-G4A (ADR-644) — tableau de bord Planning : fusionne shifts + absences (W-G3) sur une plage de
 * dates bornée, scopé (manager) et filtré CÔTÉ SERVEUR, et détecte les CHEVAUCHEMENTS shift ↔ congé
 * approuvé (aide à la décision, aucune action automatique). Fournit aussi une comparaison
 * PRÉVU / RÉALISÉ (shifts planifiés vs temps pointés), purement informative — aucun recalcul paie.
 *
 * Performance : requêtes bornées par dates + eager-load (pas de N+1), pas de chargement historique.
 */
class PlanningBoardReadModel
{
    /**
     * @param  array<int>|null  $visibleIds  scope (null = vue globale planning/RH)
     * @param  array<string,mixed>  $filters  department_id/employee_id/status/location_id
     */
    public static function board(int $companyId, Carbon $from, Carbon $to, ?array $visibleIds = null, array $filters = []): array
    {
        // ── Shifts (brouillon + publié + complété ; annulés exclus) ──
        $shifts = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->notCancelled()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['location_id']), fn ($q) => $q->where('work_location_id', (int) $filters['location_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', (int) $filters['department_id'])))
            ->with(['employee:id,first_name,last_name,department_id', 'workLocation:id,name,type'])
            ->orderBy('start_at')
            ->get();

        // ── Absences (approuvées + en attente) sur la même plage + même scope ──
        $leaves = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CONSUMED])
            ->where('date_from', '<=', $to->toDateString())
            ->where('date_to', '>=', $from->toDateString())
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', (int) $filters['department_id'])))
            ->with(['employee:id,first_name,last_name', 'leaveType:id,name'])
            ->get();

        // ── Chevauchements : shift dont la DATE tombe dans une absence APPROUVÉE du même salarié ──
        $approvedByEmployee = $leaves->where('status', LeaveRequest::STATUS_APPROVED)->groupBy('employee_id');
        $conflicts = [];
        foreach ($shifts as $shift) {
            $empLeaves = $approvedByEmployee->get($shift->employee_id);
            if (! $empLeaves) {
                continue;
            }
            foreach ($empLeaves as $leave) {
                if ($shift->date->toDateString() >= $leave->date_from->toDateString()
                    && $shift->date->toDateString() <= $leave->date_to->toDateString()) {
                    $conflicts[] = [
                        'shift_id' => $shift->id,
                        'leave_id' => $leave->id,
                        'employee_id' => $shift->employee_id,
                        'employee_name' => $shift->employee->full_name,
                        'date' => $shift->date->toDateString(),
                        'leave_type' => $leave->leaveType?->name,
                        // Chevauchement shift/congé approuvé = AVERTISSEMENT (à résoudre), pas une
                        // infraction légale automatique. Les violations de règles sont bloquées à
                        // l'écriture (ShiftRuleValidator), donc absentes ici.
                        'type' => 'approved_leave',
                        'severity' => $shift->status === Shift::STATUS_DRAFT ? 'warning' : 'info',
                        'resolvable' => $shift->status === Shift::STATUS_DRAFT,
                    ];
                    break;
                }
            }
        }
        $conflictShiftIds = array_column($conflicts, 'shift_id', 'shift_id');

        return [
            'shifts' => $shifts->map(fn (Shift $s) => [
                'id' => 'shift-'.$s->id,
                'shift_id' => $s->id,
                'type' => 'shift',
                'title' => $s->employee->full_name,
                'start' => $s->start_at->toIso8601String(),
                'end' => $s->end_at->toIso8601String(),
                'allDay' => false,
                'employee_id' => $s->employee_id,
                'employee_name' => $s->employee->full_name,
                'status' => $s->status,                       // draft | published | completed
                'is_published' => $s->status !== Shift::STATUS_DRAFT,
                'location' => $s->workLocation?->name,
                'planned_minutes' => $s->plannedWorkMinutes(),
                'date' => $s->date->toDateString(),
                'timezone' => $s->timezone,
                'updated_at' => $s->updated_at?->toIso8601String(), // W-G4B : verrou optimiste côté client
                'has_conflict' => isset($conflictShiftIds[$s->id]),
            ])->values(),
            'absences' => $leaves->map(fn (LeaveRequest $l) => [
                'id' => 'leave-'.$l->id,
                'leave_id' => $l->id,
                'type' => 'absence',
                'title' => $l->employee?->full_name,
                'start' => $l->date_from->toDateString(),
                'end' => $l->date_to->copy()->addDay()->toDateString(), // FullCalendar fin exclusive
                'allDay' => true,
                'employee_id' => $l->employee_id,
                'employee_name' => $l->employee?->full_name,
                'leave_type' => $l->leaveType?->name,
                'status' => $l->status,                        // pending | approved | consumed
            ])->values(),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * W-G4B — suggestions de disponibilité pour une (ré)affectation sur une plage horaire donnée.
     * Scopé + borné. Marque chaque candidat : indisponible (congé approuvé), occupé (shift
     * chevauchant), disponible, ou « à vérifier » (infos insuffisantes). JAMAIS d'auto-sélection ;
     * ne présume PAS que deux salariés au même poste sont interchangeables (jobrole = simple indice).
     *
     * @param  array<int>|null  $visibleIds
     */
    public static function availability(int $companyId, Carbon $start, Carbon $end, ?int $excludeShiftId = null, ?int $jobRoleId = null, ?array $visibleIds = null): array
    {
        $date = $start->toDateString();

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', Employee::STATUS_ACTIVE)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->with(['currentContract:id,employee_id,status', 'jobRole:id,title'])
            ->orderBy('last_name')
            ->limit(200)
            ->get();

        // Congés approuvés couvrant la date (une requête).
        $onLeave = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)
            ->pluck('employee_id')->flip();

        // Shifts chevauchant la plage (hors shift en cours d'édition) — une requête.
        $busy = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->notCancelled()
            ->when($excludeShiftId, fn ($q) => $q->where('id', '!=', $excludeShiftId))
            ->where('start_at', '<', $end)->where('end_at', '>', $start)
            ->pluck('employee_id')->flip();

        return $employees->map(function (Employee $e) use ($onLeave, $busy, $jobRoleId) {
            $hasContract = $e->currentContract && $e->currentContract->status === 'active';
            if ($onLeave->has($e->id)) {
                $status = 'unavailable';
            } elseif ($busy->has($e->id)) {
                $status = 'busy';
            } elseif (! $hasContract) {
                $status = 'to_verify'; // infos insuffisantes (pas de contrat actif) → ne pas affirmer dispo
            } else {
                $status = 'available';
            }

            return [
                'employee_id' => $e->id,
                'employee_name' => $e->full_name,
                'job_role' => $e->jobRole?->title,
                'availability' => $status,
                'job_role_match' => $jobRoleId ? ($e->job_role_id === $jobRoleId) : null,
            ];
        })->sortBy(fn ($c) => ['available' => 0, 'to_verify' => 1, 'busy' => 2, 'unavailable' => 3][$c['availability']])
            ->values()->all();
    }

    /**
     * Comparaison PRÉVU (shifts) / RÉALISÉ (pointages complétés) par collaborateur, bornée.
     * Informatif uniquement — distingue prévu / constaté / manquant ; aucun recalcul d'heures sup.
     *
     * @param  array<int>|null  $visibleIds
     */
    public static function comparison(int $companyId, Carbon $from, Carbon $to, ?array $visibleIds = null, ?int $employeeId = null): array
    {
        $plannedByEmp = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->notCancelled()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->with('employee:id,first_name,last_name')
            ->get()
            ->groupBy('employee_id');

        $actualByEmp = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('clock_in', [$from->startOfDay(), $to->endOfDay()])
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->get()
            ->groupBy('employee_id');

        $employeeIds = collect($plannedByEmp->keys())->merge($actualByEmp->keys())->unique();

        return $employeeIds->map(function ($empId) use ($plannedByEmp, $actualByEmp) {
            $shifts = $plannedByEmp->get($empId, collect());
            $entries = $actualByEmp->get($empId, collect());
            $planned = (int) $shifts->sum(fn ($s) => $s->plannedWorkMinutes());
            $actual = (int) $entries->where('status', TimeEntry::STATUS_COMPLETED)->sum('total_worked_minutes');
            $emp = $shifts->first()?->employee ?? $entries->first()?->employee;

            return [
                'employee_id' => $empId,
                'employee_name' => $emp?->full_name,
                'planned_minutes' => $planned,
                'actual_minutes' => $actual,
                'break_minutes' => (int) $entries->sum('total_break_minutes'),
                'gap_minutes' => $actual - $planned,                              // + = au-delà, − = en-deçà
                'has_actual' => $entries->isNotEmpty(),
                'missing' => $planned > 0 && $entries->where('status', TimeEntry::STATUS_COMPLETED)->isEmpty(),
            ];
        })->values()->all();
    }
}
