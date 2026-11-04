<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;

/**
 * Couverture PERSONNEL par SOUS-INTERVALLES (Smart Planning SP1a.1, ADR-708 ; remplace le binaire SP1a).
 *
 * Pour chaque besoin applicable à chaque jour, la fenêtre est découpée en sous-intervalles aux bornes des
 * shifts éligibles. À chaque sous-intervalle, on compte les EMPLOYÉS DISTINCTS réellement présents (shift
 * couvrant intégralement le segment, hors fenêtre de pause de l'employé). On expose sous-effectif ET
 * sureffectif PAR intervalle ; le statut du besoin = pire sous-intervalle ; l'« affecté » agrégé = minimum
 * garanti (jamais une approximation présentée comme garantie).
 *
 * Gère : présence partielle, pauses, plusieurs shifts, nuit (minuit), multi-lieux, filtres Department,
 * besoins récurrents, distinct employees. Lecture seule. Balayage O(shifts·segments) — borné pour 200 pers.
 */
class CoverageReadModel
{
    public static function forRange(int $companyId, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();

        $requirements = CoverageRequirement::withoutGlobalScopes()
            ->where('company_id', $companyId)->enabled()
            ->orderBy('priority')->orderBy('start_time')->get();

        $shifts = Shift::withoutGlobalScopes()
            ->where('company_id', $companyId)->notCancelled()
            ->whereDate('date', '>=', $fromDate->toDateString())
            ->whereDate('date', '<=', $toDate->toDateString())
            ->get();

        $deptByEmployee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)->pluck('department_id', 'id');

        $reqRows = [];
        $daily = [];
        for ($d = $fromDate->copy(); $d->lte($toDate); $d->addDay()) {
            $daily[$d->toDateString()] = ['needed' => 0, 'assigned' => 0, 'gap' => 0, 'requirements' => 0];
        }

        foreach ($requirements as $req) {
            for ($d = $fromDate->copy(); $d->lte($toDate); $d->addDay()) {
                if (! $req->appliesToDate($d)) {
                    continue;
                }
                $dayKey = $d->toDateString();
                $headcount = (int) $req->headcount;

                $eligible = $shifts->filter(function (Shift $s) use ($req, $d, $deptByEmployee) {
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

                    return true;
                })->values();

                [$winStart, $winEnd] = self::window($req, $d, $eligible);

                $intervals = [];
                $minAssigned = $headcount; // si aucune couverture requise sur fenêtre vide
                $maxAssigned = 0;

                if ($winStart && $winEnd && $winStart->lt($winEnd)) {
                    $segments = self::segments($winStart, $winEnd, $eligible);
                    $minAssigned = PHP_INT_MAX;
                    foreach ($segments as [$segStart, $segEnd]) {
                        $emp = [];
                        foreach ($eligible as $s) {
                            if ($s->start_at->lte($segStart) && $s->end_at->gte($segEnd)
                                && ! self::onBreak($s, $segStart, $segEnd)) {
                                $emp[$s->employee_id] = true;
                            }
                        }
                        $assigned = count($emp);
                        $minAssigned = min($minAssigned, $assigned);
                        $maxAssigned = max($maxAssigned, $assigned);
                        $intervals[] = [
                            'start' => $segStart->toTimeString('minute'),
                            'end' => $segEnd->toTimeString('minute'),
                            'assigned' => $assigned,
                            'status' => $assigned >= $headcount ? ($assigned > $headcount ? 'over' : 'covered') : 'under',
                        ];
                    }
                    if ($minAssigned === PHP_INT_MAX) {
                        $minAssigned = 0;
                    }
                } else {
                    $minAssigned = 0; // fenêtre non déterminable (aucun shift) → non couvert
                }

                $status = $minAssigned >= $headcount
                    ? ($maxAssigned > $headcount ? 'over' : 'covered')
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
                    'assigned' => $minAssigned,            // minimum garanti sur la fenêtre
                    'peak_assigned' => $maxAssigned,
                    'gap' => $headcount - $minAssigned,
                    'status' => $status,
                    'intervals' => $intervals,
                ];

                $daily[$dayKey]['needed'] += $headcount;
                $daily[$dayKey]['assigned'] += $minAssigned;
                $daily[$dayKey]['gap'] += ($headcount - $minAssigned);
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
     * Fenêtre du besoin. Si start/end déclarés → cette fenêtre (minuit géré). Sinon (journée entière) →
     * de la première prise de poste à la dernière fin parmi les shifts éligibles (journée opérationnelle).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private static function window(CoverageRequirement $req, Carbon $date, $eligible): array
    {
        if (! empty($req->start_time) && ! empty($req->end_time)) {
            $start = Carbon::parse($date->toDateString().' '.substr($req->start_time, 0, 5));
            $end = Carbon::parse($date->toDateString().' '.substr($req->end_time, 0, 5));
            if ($end->lte($start)) {
                $end->addDay();
            }

            return [$start, $end];
        }

        if ($eligible->isEmpty()) {
            return [null, null];
        }

        return [
            $eligible->min(fn (Shift $s) => $s->start_at) ? Carbon::parse($eligible->pluck('start_at')->min()) : null,
            $eligible->max(fn (Shift $s) => $s->end_at) ? Carbon::parse($eligible->pluck('end_at')->max()) : null,
        ];
    }

    /**
     * Découpe [winStart,winEnd] aux bornes des shifts (clampées à la fenêtre).
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private static function segments(Carbon $winStart, Carbon $winEnd, $eligible): array
    {
        $points = [$winStart->getTimestamp(), $winEnd->getTimestamp()];
        foreach ($eligible as $s) {
            foreach ([$s->start_at, $s->end_at] as $p) {
                $ts = $p->getTimestamp();
                if ($ts > $winStart->getTimestamp() && $ts < $winEnd->getTimestamp()) {
                    $points[] = $ts;
                }
            }
        }
        $points = array_values(array_unique($points));
        sort($points);

        $segments = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            $segments[] = [Carbon::createFromTimestamp($points[$i]), Carbon::createFromTimestamp($points[$i + 1])];
        }

        return $segments;
    }

    /** L'employé est-il en pause (fenêtre déclarée du shift) pendant ce segment ? */
    private static function onBreak(Shift $s, Carbon $segStart, Carbon $segEnd): bool
    {
        $windows = $s->template_snapshot['break_windows'] ?? null;
        if (! is_array($windows)) {
            return false;
        }
        foreach ($windows as $w) {
            if (empty($w['start']) || empty($w['end'])) {
                continue;
            }
            $bStart = Carbon::parse($s->start_at->toDateString().' '.substr($w['start'], 0, 5));
            $bEnd = Carbon::parse($s->start_at->toDateString().' '.substr($w['end'], 0, 5));
            if ($bEnd->lte($bStart)) {
                $bEnd->addDay();
            }
            // segment entièrement dans la pause → non présent
            if ($segStart->gte($bStart) && $segEnd->lte($bEnd)) {
                return true;
            }
        }

        return false;
    }
}
