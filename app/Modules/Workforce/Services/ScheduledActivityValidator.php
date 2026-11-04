<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use Illuminate\Support\Carbon;

/**
 * Validation INTRA-SHIFT des activités planifiées (Smart Planning SP1b, ADR-705 v2).
 *
 * Contrôles DURS (DomainException → 422) : bornes du shift, fin > début, chevauchements incompatibles,
 * capacité (respect de la pause planifiée). Contrôles SOUPLES (warnings non bloquants) : cohérence de lieu /
 * déplacement quand l'information manque (on SIGNALE la limite, on ne déclare pas réalisable).
 *
 * NE réimplémente PAS WorkforceRuleResolver : aucune règle légale/CC ici (celles-ci restent au niveau Shift,
 * P1a/P1b). Ici, uniquement la cohérence opérationnelle d'un shift et de ses activités.
 */
class ScheduledActivityValidator
{
    /**
     * Lève une DomainException sur toute violation dure. Ne mute rien.
     */
    public function assertValid(Shift $shift, Carbon $startAt, Carbon $endAt, array $opts = [], ?int $ignoreId = null): void
    {
        if ($endAt->lte($startAt)) {
            throw new \DomainException('Activity end must be after its start.');
        }

        // Bornes du shift (les datetimes gèrent nativement le passage de minuit).
        if ($startAt->lt($shift->start_at) || $endAt->gt($shift->end_at)) {
            throw new \DomainException('Activity must stay within the shift boundaries.');
        }

        $others = ScheduledActivity::withoutGlobalScopes()
            ->where('shift_id', $shift->id)
            ->where('status', ScheduledActivity::STATUS_PLANNED)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        // Chevauchement incompatible (sauf activité explicitement non exclusive — jamais par défaut).
        $nonExclusive = (bool) ($opts['non_exclusive'] ?? false);
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

        // Capacité : la somme des activités (hors non exclusives) ne dépasse pas le temps travaillable
        // du shift (durée − pause planifiée) → respecte la pause même sans position connue.
        $workable = $shift->plannedWorkMinutes();
        $used = $others->reject(fn ($o) => $o->metadata['non_exclusive'] ?? false)
            ->sum(fn ($o) => max(0, (int) $o->start_at->diffInMinutes($o->end_at)));
        $candidate = max(0, (int) $startAt->diffInMinutes($endAt));
        if (! $nonExclusive && ($used + $candidate) > $workable) {
            throw new \DomainException('Activities exceed the shift workable time (break preserved).');
        }
    }

    /**
     * Warnings non bloquants (ex. changement de lieu sans intervalle de déplacement connu).
     *
     * @return list<string>
     */
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
                // Deux activités sur des lieux différents qui se touchent sans intervalle : temps de trajet
                // inconnu → on SIGNALE (ne bloque pas, ne déclare pas réalisable).
                if ($startAt->eq($o->end_at) || $endAt->eq($o->start_at)) {
                    $warnings[] = 'travel_time_unknown';
                    break;
                }
            }
        }

        return $warnings;
    }
}
