<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Suggestion de DÉCOMPTE d'une absence (Smart Planning Lot 3 complément, ADR-717).
 *
 * Fonction PURE et documentée : à partir d'une fenêtre d'absence + du contrat, propose un décompte en
 * jours et en heures. Elle N'ARRONDIT PAS automatiquement (ex. 4 h NE deviennent PAS 0,5 jour de CP) :
 * le décompte horaire est proportionnel aux heures contractuelles journalières. La valeur reste une
 * SUGGESTION : le décideur/formulaire peut la surcharger, et c'est cette valeur transmise qui fait foi.
 * Ne modifie aucun solde historique ni aucun calcul Payroll.
 *
 * Matrice (heures contractuelles journalières = weekly_hours / jours travaillés, défaut 5) :
 *  - Jour entier (times NULL, 1 jour)            → 1,00 jour ; heures = daily.
 *  - Plusieurs jours entiers (N jours)           → N,00 jours ; heures = N × daily.
 *  - Demi-journée (preset ~ daily/2)             → fraction réelle (≈0,50) ; heures = daily/2.
 *  - Quelques heures (fenêtre h)                 → h / daily jour (NON arrondi) ; heures = h.
 *  - Période traversant plusieurs jours + bornes → somme des fenêtres jour par jour.
 */
class LeaveDeductionCalculator
{
    /** Jours travaillés par défaut si non déductible du contrat. */
    private const DEFAULT_WORKING_DAYS = 5;

    /**
     * @return array{days_count_hundredths:int, hours_count_hundredths:int, daily_hours:float}
     */
    public function suggest(
        EmploymentContract $contract,
        string $dateFrom,
        string $dateTo,
        ?string $startTime = null,
        ?string $endTime = null,
    ): array {
        $dailyHours = $this->dailyHours($contract);

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();

        // Fenêtre partielle sur une SEULE journée → décompte proportionnel aux heures.
        if ($from->equalTo($to) && ($startTime || $endTime)) {
            $hours = $this->windowHours($dateFrom, $startTime, $endTime);
            $daysHundredths = $dailyHours > 0 ? (int) round($hours / $dailyHours * 100) : 0;

            return [
                'days_count_hundredths' => $daysHundredths,
                'hours_count_hundredths' => (int) round($hours * 100),
                'daily_hours' => $dailyHours,
            ];
        }

        // Jour entier / plusieurs jours entiers (bornes partielles éventuelles sur 1er/dernier jour).
        $days = 0.0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $sTime = ($d->toDateString() === $from->toDateString()) ? $startTime : null;
            $eTime = ($d->toDateString() === $to->toDateString()) ? $endTime : null;
            if ($sTime || $eTime) {
                $hours = $this->windowHours($d->toDateString(), $sTime, $eTime);
                $days += $dailyHours > 0 ? $hours / $dailyHours : 0;
            } else {
                $days += 1.0;
            }
        }

        return [
            'days_count_hundredths' => (int) round($days * 100),
            'hours_count_hundredths' => (int) round($days * $dailyHours * 100),
            'daily_hours' => $dailyHours,
        ];
    }

    /** Heures contractuelles journalières (weekly_hours / jours travaillés). */
    private function dailyHours(EmploymentContract $contract): float
    {
        $weekly = (float) ($contract->weekly_hours ?? 0);
        if ($weekly <= 0) {
            return 0.0;
        }

        return round($weekly / self::DEFAULT_WORKING_DAYS, 2);
    }

    /** Durée en heures d'une fenêtre [start,end] un jour donné ; bornes NULL = journée (0 → daily géré ailleurs). */
    private function windowHours(string $date, ?string $startTime, ?string $endTime): float
    {
        if (! $startTime && ! $endTime) {
            return 0.0;
        }
        $start = Carbon::parse($date.' '.substr($startTime ?? '00:00', 0, 5));
        $end = Carbon::parse($date.' '.substr($endTime ?? '00:00', 0, 5));
        if (! $endTime) {
            $end = Carbon::parse($date.' 00:00')->addDay();
        }
        if ($end->lte($start)) {
            return 0.0;
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }
}
