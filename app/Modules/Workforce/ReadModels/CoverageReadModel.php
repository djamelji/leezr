<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;

/**
 * Couverture PERSONNEL QUALIFIÉE par sous-intervalles (Smart Planning Lot A, ADR-710).
 *
 * Distingue la présence RH de la COUVERTURE OPÉRATIONNELLE : un besoin cible une CATÉGORIE (Route, Formation…).
 * À chaque sous-intervalle, on compte les employés DISTINCTS dont la catégorie EFFECTIVE contribue à celle
 * du besoin :
 *   - l'ACTIVITÉ planifiée couvrant l'intervalle (si elle a une catégorie) PRIME (shift polyvalent) ;
 *   - sinon la catégorie du SHIFT (snapshot du modèle) ;
 *   - une plage sans activité et sans catégorie de shift n'est JAMAIS comptée comme production.
 * → une formation 09-12 puis route 13-17 ne compte PAS comme route toute la journée ; 3 formations ne
 *   gonflent pas la couverture Route. Aucune pondération inventée. Rétro-compatible : un besoin sans
 *   catégorie retombe sur l'ancien comportement (toute présence éligible compte).
 *
 * Lecture seule. Balayage O(shifts·segments) borné.
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

        // Activités planifiées des shifts chargés (pour qualifier les sous-intervalles).
        $activitiesByShift = ScheduledActivity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->where('status', ScheduledActivity::STATUS_PLANNED)
            ->get()->groupBy('shift_id');

        $deptByEmployee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)->pluck('department_id', 'id');

        $categories = CoverageCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)->get()->keyBy('id');

        $reqRows = [];
        $daily = [];
        for ($d = $fromDate->copy(); $d->lte($toDate); $d->addDay()) {
            $daily[$d->toDateString()] = ['needed' => 0, 'assigned' => 0, 'gap' => 0, 'requirements' => 0];
        }

        foreach ($requirements as $req) {
            $catId = $req->coverage_category_id;
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

                    return true;
                })->values();

                [$winStart, $winEnd] = self::window($req, $d, $eligible);

                $intervals = [];
                $minAssigned = 0;
                $maxAssigned = 0;

                if ($winStart && $winEnd && $winStart->lt($winEnd)) {
                    $segments = self::segments($winStart, $winEnd, $eligible, $activitiesByShift);
                    $minAssigned = PHP_INT_MAX;
                    foreach ($segments as [$segStart, $segEnd]) {
                        $emp = [];
                        foreach ($eligible as $s) {
                            if (! ($s->start_at->lte($segStart) && $s->end_at->gte($segEnd))) {
                                continue;
                            }
                            if (self::onBreak($s, $segStart, $segEnd)) {
                                continue;
                            }
                            if ($catId !== null && ! self::contributes($s, $activitiesByShift->get($s->id), $segStart, $segEnd, $catId)) {
                                continue;
                            }
                            $emp[$s->employee_id] = true;
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
                }

                $status = $minAssigned >= $headcount
                    ? ($maxAssigned > $headcount ? 'over' : 'covered')
                    : 'under';

                $cat = $catId ? $categories->get($catId) : null;

                $reqRows[] = [
                    'requirement_id' => $req->id,
                    'label' => $req->label,
                    'date' => $dayKey,
                    'day_of_week' => (int) $d->dayOfWeek,
                    'start_time' => $req->start_time,
                    'end_time' => $req->end_time,
                    'department_id' => $req->department_id,
                    'work_location_id' => $req->work_location_id,
                    'coverage_category_id' => $catId,
                    'category_code' => $cat?->code,
                    'category_name' => $cat?->name,
                    'category_color' => $cat?->color,
                    'priority' => $req->priority,
                    'headcount' => $headcount,
                    'assigned' => $minAssigned,
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
     * L'employé contribue-t-il à la catégorie $catId sur [segStart,segEnd] ?
     * Activité couvrante qualifiée PRIME ; sinon catégorie du shift ; sinon non (pas de production par défaut).
     */
    private static function contributes(Shift $s, $activities, Carbon $segStart, Carbon $segEnd, int $catId): bool
    {
        $effective = null;
        if ($activities) {
            foreach ($activities as $a) {
                if ($a->start_at->lte($segStart) && $a->end_at->gte($segEnd)) {
                    if ($a->coverage_category_id !== null) {
                        $effective = (int) $a->coverage_category_id;
                        break;
                    }
                    // Activité sans catégorie : neutralise la catégorie du shift sur ce créneau (travail qualifié autre).
                    $effective = 0;
                }
            }
        }
        if ($effective === null) {
            $effective = $s->template_snapshot['coverage_category_id'] ?? null;
            $effective = $effective !== null ? (int) $effective : null;
        }

        return $effective === $catId;
    }

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

        return [Carbon::parse($eligible->pluck('start_at')->min()), Carbon::parse($eligible->pluck('end_at')->max())];
    }

    /** Découpe aux bornes des shifts ET des activités (changements de catégorie effective). */
    private static function segments(Carbon $winStart, Carbon $winEnd, $eligible, $activitiesByShift): array
    {
        $points = [$winStart->getTimestamp(), $winEnd->getTimestamp()];
        $add = function ($ts) use (&$points, $winStart, $winEnd) {
            if ($ts > $winStart->getTimestamp() && $ts < $winEnd->getTimestamp()) {
                $points[] = $ts;
            }
        };
        foreach ($eligible as $s) {
            $add($s->start_at->getTimestamp());
            $add($s->end_at->getTimestamp());
            foreach (($activitiesByShift->get($s->id) ?? []) as $a) {
                $add($a->start_at->getTimestamp());
                $add($a->end_at->getTimestamp());
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
            if ($segStart->gte($bStart) && $segEnd->lte($bEnd)) {
                return true;
            }
        }

        return false;
    }
}
