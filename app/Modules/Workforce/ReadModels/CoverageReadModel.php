<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;

/**
 * Couverture PERSONNEL constatée sur une plage (Smart Planning SP1a, ADR-705).
 *
 * Pour chaque besoin applicable à chaque jour de la plage, calcule la présence à partir des shifts
 * éligibles (non annulés) :
 *   - jamais le même collaborateur compté deux fois dans un même intervalle (DISTINCT employee_id) ;
 *   - un shift seulement partiellement présent sur la fenêtre n'est PAS compté comme couverture complète
 *     (il doit couvrir intégralement [start,end] de la fenêtre ; sans fenêtre = n'importe quel shift du jour) ;
 *   - filtres de portée : Department (via l'employé), lieu, type d'activité (snapshot du modèle).
 *
 * Lecture seule — ne mute rien. Distinct de la demande d'activité (workload, SP2).
 */
class CoverageReadModel
{
    /**
     * @return array{requirements: list<array>, daily: array<string, array>, range: array{from:string,to:string}}
     */
    public static function forRange(int $companyId, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();

        $requirements = CoverageRequirement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->enabled()
            ->orderBy('priority')
            ->orderBy('start_time')
            ->get();

        // Charge les shifts éligibles de la plage une seule fois.
        $shifts = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->notCancelled()
            ->whereDate('date', '>=', $fromDate->toDateString())
            ->whereDate('date', '<=', $toDate->toDateString())
            ->get();

        // Map employé -> department (pour les besoins scopés Department).
        $deptByEmployee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('department_id', 'id');

        $reqRows = [];
        $daily = [];

        for ($d = $fromDate->copy(); $d->lte($toDate); $d->addDay()) {
            $dayKey = $d->toDateString();
            $daily[$dayKey] = ['needed' => 0, 'assigned' => 0, 'gap' => 0, 'requirements' => 0];
        }

        foreach ($requirements as $req) {
            for ($d = $fromDate->copy(); $d->lte($toDate); $d->addDay()) {
                if (! $req->appliesToDate($d)) {
                    continue;
                }
                $dayKey = $d->toDateString();

                [$windowStart, $windowEnd] = self::window($req, $d);

                $eligible = $shifts->filter(function (Shift $s) use ($req, $d, $deptByEmployee, $windowStart, $windowEnd) {
                    if (Carbon::parse($s->date)->toDateString() !== $d->toDateString()) {
                        return false;
                    }
                    if ($req->department_id !== null && (int) ($deptByEmployee[$s->employee_id] ?? 0) !== (int) $req->department_id) {
                        return false;
                    }
                    if ($req->work_location_id !== null && (int) $s->work_location_id !== (int) $req->work_location_id) {
                        return false;
                    }
                    if ($req->activity_type !== null && ($s->template_snapshot['activity_type'] ?? null) !== $req->activity_type) {
                        return false;
                    }
                    // Fenêtre : le shift doit couvrir INTÉGRALEMENT [start,end] (présence partielle ≠ complète).
                    if ($windowStart !== null && $windowEnd !== null) {
                        return $s->start_at->lte($windowStart) && $s->end_at->gte($windowEnd);
                    }

                    return true;
                });

                $assigned = $eligible->pluck('employee_id')->unique()->count();
                $headcount = (int) $req->headcount;
                $gap = $headcount - $assigned;

                $status = $assigned >= $headcount
                    ? ($assigned > $headcount ? 'over' : 'covered')
                    : 'under';

                $reqRows[] = [
                    'requirement_id' => $req->id,
                    'label' => $req->label,
                    'date' => $dayKey,
                    'day_of_week' => (int) $d->dayOfWeek,
                    'start_time' => $req->start_time,
                    'end_time' => $req->end_time,
                    'department_id' => $req->department_id,
                    'work_location_id' => $req->work_location_id,
                    'activity_type' => $req->activity_type,
                    'priority' => $req->priority,
                    'headcount' => $headcount,
                    'assigned' => $assigned,
                    'gap' => $gap,
                    'status' => $status,
                ];

                $daily[$dayKey]['needed'] += $headcount;
                $daily[$dayKey]['assigned'] += $assigned;
                $daily[$dayKey]['gap'] += $gap;
                $daily[$dayKey]['requirements']++;
            }
        }

        return [
            'requirements' => $reqRows,
            'daily' => $daily,
            'range' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()],
        ];
    }

    /**
     * Fenêtre horaire du besoin pour une date. Null si journée entière.
     * Gère le passage de minuit (end < start → le lendemain).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private static function window(CoverageRequirement $req, Carbon $date): array
    {
        if (empty($req->start_time) || empty($req->end_time)) {
            return [null, null];
        }

        $start = Carbon::parse($date->toDateString().' '.substr($req->start_time, 0, 5));
        $end = Carbon::parse($date->toDateString().' '.substr($req->end_time, 0, 5));
        if ($end->lte($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }
}
