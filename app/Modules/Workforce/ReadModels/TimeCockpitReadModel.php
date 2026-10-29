<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetPeriod;
use Carbon\Carbon;

/**
 * W-G5 (ADR-646) — cockpit Pointage : synthèse opérationnelle + tableau collaborateur réconciliant
 * le PRÉVU (shifts) et le RÉALISÉ (pointages) sur une date bornée, scopé (manager) côté serveur.
 *
 * Doctrine : états issus des modèles existants, jamais déduits arbitrairement. Distingue
 * sans-pointage / absence approuvée / non-planifié / incomplet. Rattachement shift↔pointage marqué
 * « à vérifier » si ambigu — jamais d'association forcée. Aucun recalcul paie/heures sup.
 *
 * @param array<int>|null $visibleIds scope (null = vue globale RH/validateur)
 */
class TimeCockpitReadModel
{
    /** Indicateurs du jour, scopés. */
    public static function summary(int $companyId, Carbon $date, ?array $visibleIds = null): array
    {
        $d = $date->toDateString();

        $entries = TimeEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('date', $d)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->get(['id', 'employee_id', 'status', 'clock_in', 'clock_out', 'date']);

        // Incomplet = pointage ouvert (clock_in sans clock_out) sur un jour PASSÉ (oubli de pointer la fin).
        $incomplete = $entries->filter(fn ($e) => $e->clock_in && ! $e->clock_out && $e->date->toDateString() < now()->toDateString())->count();

        // Shifts prévus ce jour sans aucun pointage du collaborateur → « non pointé » (hors congé approuvé).
        $shiftEmp = Shift::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('date', $d)->notCancelled()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->pluck('employee_id')->unique();
        $clockedEmp = $entries->pluck('employee_id')->unique()->flip();
        $onLeaveEmp = self::approvedLeaveEmployees($companyId, $d, $visibleIds);
        $notClocked = $shiftEmp->reject(fn ($id) => $clockedEmp->has($id) || $onLeaveEmp->has($id))->count();

        $anomalies = count(TimeEntryReadModel::detectAnomalies($companyId, $d, $d));

        $pendingTimesheets = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', TimesheetPeriod::STATUS_SUBMITTED)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->count();

        return [
            'date' => $d,
            'working' => $entries->where('status', TimeEntry::STATUS_WORKING)->count(),
            'on_break' => $entries->where('status', TimeEntry::STATUS_ON_BREAK)->count(),
            'completed' => $entries->where('status', TimeEntry::STATUS_COMPLETED)->count(),
            'not_clocked' => $notClocked,
            'incomplete' => $incomplete,
            'anomalies' => $anomalies,
            'pending_timesheets' => $pendingTimesheets,
        ];
    }

    /**
     * Tableau opérationnel du jour : une ligne par collaborateur concerné (shift prévu OU pointage OU
     * absence), avec prévu/réalisé réconciliés et le flag de rattachement.
     *
     * @param array<string,mixed> $filters department_id/employee_id/status
     */
    public static function board(int $companyId, Carbon $date, ?array $visibleIds = null, array $filters = []): array
    {
        $d = $date->toDateString();

        $shifts = Shift::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('date', $d)->notCancelled()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', (int) $filters['department_id'])))
            ->with('employee:id,first_name,last_name,department_id')
            ->get()->groupBy('employee_id');

        $entries = TimeEntry::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('date', $d)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', (int) $filters['department_id'])))
            ->with('employee:id,first_name,last_name,department_id')
            ->get()->groupBy('employee_id');

        $onLeave = self::approvedLeaveEmployees($companyId, $d, $visibleIds);
        $empIds = $shifts->keys()->merge($entries->keys())->unique();

        $rows = $empIds->map(function ($empId) use ($shifts, $entries, $onLeave, $filters) {
            $empShifts = $shifts->get($empId, collect());
            $empEntries = $entries->get($empId, collect());
            $emp = $empShifts->first()?->employee ?? $empEntries->first()?->employee;

            // Rattachement : 1 shift + 1 pointage = certain ; sinon ambigu / non rattaché.
            $reconciliation = 'no_shift';
            $shift = null;
            $entry = $empEntries->sortBy('clock_in')->first();
            if ($empShifts->count() === 1 && $empEntries->count() <= 1) {
                $shift = $empShifts->first();
                $reconciliation = $empEntries->isEmpty() ? 'not_clocked' : 'matched';
            } elseif ($empShifts->count() >= 1 && $empEntries->count() >= 1) {
                $reconciliation = 'ambiguous'; // plusieurs shifts/pointages → rattachement à vérifier
                $shift = $empShifts->sortBy('start_at')->first();
            } elseif ($empShifts->count() > 1) {
                $reconciliation = 'not_clocked';
                $shift = $empShifts->sortBy('start_at')->first();
            }

            $worked = (int) $empEntries->where('status', TimeEntry::STATUS_COMPLETED)->sum('total_worked_minutes');
            $plannedMin = (int) $empShifts->sum(fn ($s) => $s->plannedWorkMinutes());
            $status = $onLeave->has($empId) ? 'on_leave' : ($entry?->status ?? 'idle');

            return [
                'employee_id' => $empId,
                'employee_name' => $emp?->full_name,
                'department_id' => $emp?->department_id,
                'status' => $status,
                'planned_start' => $shift?->start_at?->toIso8601String(),
                'planned_end' => $shift?->end_at?->toIso8601String(),
                'actual_start' => $entry?->clock_in?->toIso8601String(),
                'actual_end' => $entry?->clock_out?->toIso8601String(),
                'worked_minutes' => $empEntries->isEmpty() ? null : $worked,
                'break_minutes' => $empEntries->isEmpty() ? null : (int) $empEntries->sum('total_break_minutes'),
                'planned_minutes' => $empShifts->isEmpty() ? null : $plannedMin,
                'gap_minutes' => ($empEntries->isNotEmpty() && $empShifts->isNotEmpty()) ? ($worked - $plannedMin) : null,
                'reconciliation' => $reconciliation,      // matched | ambiguous | not_clocked | no_shift
                'time_entry_id' => $entry?->id,
                'on_leave' => $onLeave->has($empId),
                'incomplete' => (bool) ($entry && $entry->clock_in && ! $entry->clock_out && $entry->date->toDateString() < now()->toDateString()),
            ];
        });

        if (! empty($filters['status'])) {
            $rows = $rows->where('status', $filters['status']);
        }

        return $rows->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int,int> employee_id => key (flip) des congés approuvés ce jour */
    private static function approvedLeaveEmployees(int $companyId, string $date, ?array $visibleIds)
    {
        return LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->pluck('employee_id')->unique()->flip();
    }
}
