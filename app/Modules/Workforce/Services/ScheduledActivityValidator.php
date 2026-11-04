<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;

/**
 * Validation INTRA-SHIFT des activités planifiées (Smart Planning SP1b/SP1a.1, ADR-705 v2 / ADR-708).
 *
 * Contrôles DURS (DomainException → 422) :
 *  - fin > début ; activité dans les bornes du shift ;
 *  - chevauchement incompatible (sauf activité explicitement non exclusive) ;
 *  - chevauchement d'une FENÊTRE DE PAUSE planifiée (quand elle est connue) — une activité exclusive ne
 *    peut pas occuper la pause (SP1a.1 §1A) ; gère plusieurs pauses et le passage de minuit ;
 *  - capacité : l'UNION des intervalles réellement occupés (jamais la SOMME de simultanées, SP1a.1 §1B)
 *    ne dépasse pas le temps travaillable (durée du shift − durée des pauses).
 *
 * Contrôles SOUPLES (warnings) : changement de lieu sans intervalle (temps de trajet inconnu) → signalé.
 * NE réimplémente PAS WorkforceRuleResolver (aucune règle légale/CC ici).
 */
class ScheduledActivityValidator
{
    public function assertValid(Shift $shift, Carbon $startAt, Carbon $endAt, array $opts = [], ?int $ignoreId = null): void
    {
        if ($endAt->lte($startAt)) {
            throw new \DomainException('Activity end must be after its start.');
        }
        if ($startAt->lt($shift->start_at) || $endAt->gt($shift->end_at)) {
            throw new \DomainException('Activity must stay within the shift boundaries.');
        }

        $nonExclusive = (bool) ($opts['non_exclusive'] ?? false);

        // Fenêtres de pause : une activité EXCLUSIVE ne peut pas les chevaucher (si elles sont connues).
        if (! $nonExclusive) {
            foreach ($this->breakWindows($shift) as [$bStart, $bEnd]) {
                if ($startAt->lt($bEnd) && $endAt->gt($bStart)) {
                    throw new \DomainException('Activity overlaps a planned break.');
                }
            }
        }

        $others = ScheduledActivity::withoutGlobalScopes()
            ->where('shift_id', $shift->id)
            ->where('status', ScheduledActivity::STATUS_PLANNED)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        // Chevauchement incompatible (sauf non exclusif explicite — jamais par défaut).
        if (! $nonExclusive) {
            foreach ($others as $o) {
                if ($o->metadata['non_exclusive'] ?? false) {
                    continue;
                }
                if ($startAt->lt($o->end_at) && $endAt->gt($o->start_at)) {
                    throw new \DomainException('Activity overlaps another activity in the same shift.');
                }
            }
        }

        // Capacité : UNION des intervalles occupés (candidate + toutes les activités), fusionnée → jamais
        // deux activités simultanées additionnées. Comparée au temps travaillable (durée − pauses).
        $intervals = [[$startAt->getTimestamp(), $endAt->getTimestamp()]];
        foreach ($others as $o) {
            $intervals[] = [$o->start_at->getTimestamp(), $o->end_at->getTimestamp()];
        }
        $occupiedMinutes = (int) round($this->unionLength($intervals) / 60);

        $workable = $this->workableMinutes($shift);
        if ($occupiedMinutes > $workable) {
            throw new \DomainException('Activities exceed the shift workable time (break preserved).');
        }
    }

    /** @return list<string> */
    public function warnings(Shift $shift, Carbon $startAt, Carbon $endAt, ?int $locationId, ?int $ignoreId = null): array
    {
        $warnings = [];
        if ($locationId) {
            $adjacent = ScheduledActivity::withoutGlobalScopes()
                ->where('shift_id', $shift->id)
                ->where('status', ScheduledActivity::STATUS_PLANNED)
                ->whereNotNull('work_location_id')
                ->where('work_location_id', '!=', $locationId)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->get();
            foreach ($adjacent as $o) {
                if ($startAt->eq($o->end_at) || $endAt->eq($o->start_at)) {
                    $warnings[] = 'travel_time_unknown';
                    break;
                }
            }
        }

        return $warnings;
    }

    /**
     * Fenêtres de pause planifiées du shift. Lues depuis template_snapshot['break_windows'] = [{start,end}]
     * (HH:mm). Positionnées sur le calendrier du shift (gère le passage de minuit). Vide si non déclarées
     * (seul un total scalaire break_duration_minutes est alors connu → non plaçable).
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function breakWindows(Shift $shift): array
    {
        $raw = $shift->template_snapshot['break_windows'] ?? null;
        if (! is_array($raw) || ! $raw) {
            return [];
        }

        $out = [];
        foreach ($raw as $w) {
            $s = $w['start'] ?? null;
            $e = $w['end'] ?? null;
            if (! $s || ! $e) {
                continue;
            }
            $bStart = $this->onShiftCalendar($shift, $s);
            $bEnd = $this->onShiftCalendar($shift, $e, $bStart);
            if ($bStart && $bEnd && $bStart->lt($bEnd)) {
                $out[] = [$bStart, $bEnd];
            }
        }

        return $out;
    }

    /** Place une heure HH:mm sur le calendrier du shift : premier instant >= start du shift (ou après $after). */
    private function onShiftCalendar(Shift $shift, string $hhmm, ?Carbon $after = null): ?Carbon
    {
        $time = substr($hhmm, 0, 5);
        $anchor = ($after ?? $shift->start_at)->copy();
        $candidate = Carbon::parse($shift->start_at->toDateString().' '.$time, $shift->start_at->getTimezone());
        while ($candidate->lt($after ?? $shift->start_at)) {
            $candidate->addDay();
        }
        if ($candidate->gt($shift->end_at)) {
            return null;
        }

        return $candidate;
    }

    private function workableMinutes(Shift $shift): int
    {
        $duration = (int) $shift->start_at->diffInMinutes($shift->end_at);
        $windows = $this->breakWindows($shift);
        if ($windows) {
            $breakMin = 0;
            foreach ($windows as [$s, $e]) {
                $breakMin += (int) $s->diffInMinutes($e);
            }

            return max(0, $duration - $breakMin);
        }

        return max(0, $duration - (int) ($shift->template_snapshot['break_duration_minutes'] ?? 0));
    }

    /** Longueur (secondes) de l'union d'intervalles [start,end] (fusion des recouvrements). */
    private function unionLength(array $intervals): int
    {
        if (! $intervals) {
            return 0;
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        [$curStart, $curEnd] = $intervals[0];
        foreach (array_slice($intervals, 1) as [$s, $e]) {
            if ($s > $curEnd) {
                $total += $curEnd - $curStart;
                $curStart = $s;
                $curEnd = $e;
            } elseif ($e > $curEnd) {
                $curEnd = $e;
            }
        }
        $total += $curEnd - $curStart;

        return $total;
    }
}
