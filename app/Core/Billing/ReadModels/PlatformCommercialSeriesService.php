<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\BillingMetricsCalculationService;
use App\Core\Billing\Subscription;
use App\Core\Plans\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADR-747 (P2) — séries temporelles « Pilotage commercial » pour le widget
 * platform.commercial_overview : MRR en NIVEAU (pas une somme de revenus quotidiens)
 * recalculé à la fin de chaque bucket, avec sélecteur de période (semaine/mois/année),
 * navigation et comparatif N-1.
 *
 * MÉTHODE & LIMITES (traçabilité) :
 *  - Le MRR est un NIVEAU à une date = somme du montant mensuel contracté (ADR-591,
 *    snapshot) des abonnements `is_current` actifs dont `created_at <= date`.
 *  - Aucune table de snapshot MRR n'existe → l'historique est RECONSTRUIT depuis les
 *    abonnements actuellement actifs. Conséquence assumée : il ne reflète pas le churn
 *    passé ni les changements de prix (biais « croissance »). Avant le 1er abonnement,
 *    MRR = 0 (réel : aucun revenu récurrent). `has_history` distingue « aucun abonnement
 *    n'existait sur la période N-1 » (comparaison non pertinente) d'un vrai 0.
 *  - Semaines : convention ISO-8601 (isoWeek / isoWeekYear) ; gère nativement le passage
 *    d'année et les années à 53 semaines. N-1 = même numéro de semaine ISO l'an précédent.
 *  - Période en cours : partielle → les buckets futurs sont `null` (non tracés) et
 *    `partial=true` ; le N-1 est aligné sur les MÊMES positions de bucket.
 */
class PlatformCommercialSeriesService
{
    public function __construct(private BillingMetricsCalculationService $metrics) {}

    /** @return array<string,mixed> */
    public function series(string $period, ?string $anchor, bool $compare): array
    {
        $period = in_array($period, ['week', 'month', 'year'], true) ? $period : 'month';
        $now = Carbon::now();
        $anchorDate = $anchor ? Carbon::parse($anchor) : $now->copy();

        // Abonnements actifs chargés UNE fois (recalcul in-memory par bucket).
        $plans = Plan::all()->keyBy('key');
        $subs = Subscription::where('is_current', 1)
            ->whereIn('status', ['active', 'past_due'])
            ->get(['plan_key', 'interval', 'plan_price_cents', 'created_at'])
            ->map(fn ($s) => (object) [
                'created_at' => $s->created_at,
                'amount' => $this->metrics->subscriptionMonthlyAmount($s, $plans),
            ]);
        $firstSubAt = $subs->min('created_at');

        $cur = $this->buckets($period, $anchorDate);
        $current = $this->fill($cur, $subs, $now, true);

        $previous = null;
        $hasHistory = false;
        if ($compare) {
            $prevBuckets = array_map(fn ($b) => [
                'label' => $b['label'],
                'start' => $b['start']->copy()->subYear(),
                'end' => $b['end']->copy()->subYear(),
            ], $cur);
            // N-1 pertinent seulement si un abonnement existait avant la fin de la période N-1.
            $prevPeriodEnd = end($prevBuckets)['end'];
            $hasHistory = $firstSubAt !== null && Carbon::parse($firstSubAt)->lte($prevPeriodEnd);
            $previous = $hasHistory ? $this->fill($prevBuckets, $subs, $now, false) : null;
        }

        $summary = $this->summary($current, $previous, $compare, $hasHistory);

        return [
            'period' => $period,
            'granularity' => $period === 'year' ? 'month' : 'day',
            'anchor' => $anchorDate->toIso8601String(),
            'label' => $this->periodLabel($period, $anchorDate),
            'partial' => $current['partial'],
            'can_next' => $this->periodStart($period, $anchorDate->copy()->modify($this->stepModifier($period, 1)))->lte($now),
            'compare' => $compare,
            'has_history' => $hasHistory,
            'currency' => config('billing.reporting_currency', 'EUR'),
            'labels' => array_column($cur, 'label'),
            'current' => $current['values'],
            'previous' => $previous['values'] ?? null,
            'summary' => $summary,
        ];
    }

    /** Construit les bornes de bucket (fin de chaque jour/mois) pour la période ancrée. */
    private function buckets(string $period, Carbon $anchor): array
    {
        $out = [];
        if ($period === 'week') {
            $start = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            for ($i = 0; $i < 7; $i++) {
                $d = $start->copy()->addDays($i);
                $out[] = ['label' => ucfirst($d->locale('fr')->isoFormat('dd D')), 'start' => $d->copy()->startOfDay(), 'end' => $d->copy()->endOfDay()];
            }
        } elseif ($period === 'year') {
            $start = $anchor->copy()->startOfYear();
            for ($i = 0; $i < 12; $i++) {
                $m = $start->copy()->addMonths($i);
                $out[] = ['label' => ucfirst($m->locale('fr')->isoFormat('MMM')), 'start' => $m->copy()->startOfMonth(), 'end' => $m->copy()->endOfMonth()];
            }
        } else { // month
            $start = $anchor->copy()->startOfMonth();
            $days = $anchor->copy()->daysInMonth;
            for ($i = 0; $i < $days; $i++) {
                $d = $start->copy()->addDays($i);
                $out[] = ['label' => $d->isoFormat('D'), 'start' => $d->copy()->startOfDay(), 'end' => $d->copy()->endOfDay()];
            }
        }

        return $out;
    }

    /** MRR-niveau par bucket ; buckets futurs → null (période partielle). */
    private function fill(array $buckets, Collection $subs, Carbon $now, bool $markPartial): array
    {
        $values = [];
        $partial = false;
        foreach ($buckets as $b) {
            $start = $b['start'] ?? $b['end'];
            if ($start->greaterThan($now)) {
                // Bucket entièrement futur → non tracé (période partielle).
                $values[] = null;
                $partial = true;
            } else {
                // MRR-niveau au min(fin du bucket, maintenant) : un bucket EN COURS montre
                // le niveau actuel (le MRR est un niveau connu à l'instant présent).
                $at = $b['end']->greaterThan($now) ? $now : $b['end'];
                if ($b['end']->greaterThan($now)) {
                    $partial = true;
                }
                $values[] = (int) $subs->filter(fn ($s) => Carbon::parse($s->created_at)->lte($at))->sum('amount');
            }
        }

        return ['values' => $values, 'partial' => $markPartial && $partial];
    }

    /** Résumé : dernier point réel courant vs point N-1 équivalent, écart & évolution. */
    private function summary(array $current, ?array $previous, bool $compare, bool $hasHistory): array
    {
        $lastIdx = $this->lastRealIndex($current['values']);
        $now = $lastIdx === null ? 0 : (int) $current['values'][$lastIdx];
        $prev = null;
        if ($compare && $hasHistory && $previous && $lastIdx !== null) {
            $prev = $previous['values'][$lastIdx] ?? null;
            if ($prev !== null) {
                $prev = (int) $prev;
            }
        }
        $deltaAbs = ($prev !== null) ? $now - $prev : null;
        // Pas de division par zéro : % non calculable si N-1 vaut 0.
        $deltaPct = ($prev !== null && $prev > 0) ? round(($now - $prev) / $prev, 4) : null;

        return [
            'mrr_now' => $now,
            'mrr_prev_equiv' => $prev,
            'delta_abs' => $deltaAbs,
            'delta_pct' => $deltaPct,
        ];
    }

    private function lastRealIndex(array $values): ?int
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] !== null) {
                return $i;
            }
        }

        return null;
    }

    private function periodStart(string $period, Carbon $d): Carbon
    {
        return match ($period) {
            'week' => $d->copy()->startOfWeek(Carbon::MONDAY),
            'year' => $d->copy()->startOfYear(),
            default => $d->copy()->startOfMonth(),
        };
    }

    private function stepModifier(string $period, int $dir): string
    {
        $n = $dir >= 0 ? '+1' : '-1';

        return match ($period) {
            'week' => "$n week",
            'year' => "$n year",
            default => "$n month",
        };
    }

    private function periodLabel(string $period, Carbon $d): string
    {
        return match ($period) {
            'week' => 'S'.$d->isoWeek.' · '.$d->isoWeekYear,
            'year' => (string) $d->year,
            default => ucfirst($d->locale('fr')->isoFormat('MMMM YYYY')),
        };
    }
}
