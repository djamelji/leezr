<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\ScheduledActivity;

/**
 * Valide et normalise la COMPOSITION d'un modèle de shift (Refonte Smart Planning — M1, ADR-724).
 *
 * Contrat JSON versionné stocké dans ScheduleTemplate.metadata['composition'] :
 *   {
 *     "schema_version": 1,
 *     "activities": [{id?, label?, activity_type?, coverage_category_id?, work_location_id?,
 *                     priority, non_exclusive, offset_minutes, duration_minutes}],
 *     "breaks":     [{id?, offset_minutes, duration_minutes}]
 *   }
 *
 * Offsets RELATIFS au début du shift (gère le passage de minuit : les bornes se comparent à la durée du
 * shift, pas à une heure murale). Représentation UNIQUE : ces offsets sont la seule source ; le snapshot
 * (toSnapshot) en dérive les break_windows en HH:mm pour le ScheduledActivityValidator déjà déployé.
 *
 * Autorité de l'overlap/capacité à la MATÉRIALISATION = ScheduledActivityValidator (atomique). Ici on
 * refuse en amont (authoring) les bornes invalides et les chevauchements évidents pour un retour immédiat.
 *
 * Compatibilité ascendante : composition absente (null) ⇒ modèle purement horaire, toujours valide.
 */
class ScheduleTemplateCompositionValidator
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array{schema_version:int, activities:list<array<string,mixed>>, breaks:list<array<string,mixed>>}|null
     *
     * @throws \DomainException
     */
    public function validateAndNormalize(?array $composition, string $startTime, string $endTime): ?array
    {
        if ($composition === null) {
            return null; // modèle horaire pur — pas de migration, pas de composition.
        }

        if ((int) ($composition['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new \DomainException('Unsupported composition schema_version (expected '.self::SCHEMA_VERSION.').');
        }

        $shiftDuration = $this->durationMinutes($startTime, $endTime);
        if ($shiftDuration <= 0) {
            throw new \DomainException('Invalid shift duration for composition.');
        }

        $activities = [];
        $i = 0;
        foreach (($composition['activities'] ?? []) as $a) {
            if (! is_array($a)) {
                throw new \DomainException('Invalid activity entry.');
            }
            $offset = (int) ($a['offset_minutes'] ?? -1);
            $duration = (int) ($a['duration_minutes'] ?? 0);
            if ($offset < 0 || $duration <= 0) {
                throw new \DomainException('Activity offset_minutes must be >= 0 and duration_minutes > 0.');
            }
            if ($offset + $duration > $shiftDuration) {
                throw new \DomainException('Activity exceeds the shift boundaries.');
            }
            $priority = $a['priority'] ?? 'normal';
            if (! in_array($priority, ScheduledActivity::PRIORITIES, true)) {
                throw new \DomainException('Invalid activity priority.');
            }
            $activities[] = [
                'id' => isset($a['id']) && $a['id'] !== '' ? (string) $a['id'] : 'a'.(++$i),
                // M2 (ADR-725) — provenance FACULTATIVE vers un ActivityPreset. Les valeurs métier ci-dessous
                // sont COPIÉES par valeur (autonomes) : modifier/supprimer le preset n'altère jamais la composition.
                'preset_id' => isset($a['preset_id']) && $a['preset_id'] !== null ? (int) $a['preset_id'] : null,
                'label' => isset($a['label']) ? (string) $a['label'] : null,
                'activity_type' => isset($a['activity_type']) ? (string) $a['activity_type'] : null,
                'coverage_category_id' => isset($a['coverage_category_id']) && $a['coverage_category_id'] !== null ? (int) $a['coverage_category_id'] : null,
                'work_location_id' => isset($a['work_location_id']) && $a['work_location_id'] !== null ? (int) $a['work_location_id'] : null,
                'priority' => $priority,
                'non_exclusive' => (bool) ($a['non_exclusive'] ?? false),
                'offset_minutes' => $offset,
                'duration_minutes' => $duration,
            ];
        }

        // Ordre chronologique garanti (offset croissant) — identifiants stables conservés.
        usort($activities, fn ($x, $y) => $x['offset_minutes'] <=> $y['offset_minutes']);

        $ids = array_column($activities, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new \DomainException('Duplicate activity id in composition.');
        }

        $breaks = [];
        $j = 0;
        foreach (($composition['breaks'] ?? []) as $b) {
            if (! is_array($b)) {
                throw new \DomainException('Invalid break entry.');
            }
            $offset = (int) ($b['offset_minutes'] ?? -1);
            $duration = (int) ($b['duration_minutes'] ?? 0);
            if ($offset < 0 || $duration <= 0 || $offset + $duration > $shiftDuration) {
                throw new \DomainException('Break window exceeds the shift boundaries.');
            }
            $breaks[] = [
                'id' => isset($b['id']) && $b['id'] !== '' ? (string) $b['id'] : 'b'.(++$j),
                'offset_minutes' => $offset,
                'duration_minutes' => $duration,
            ];
        }

        $this->assertNoOverlaps($activities, $breaks);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'activities' => $activities,
            'breaks' => $breaks,
        ];
    }

    /** Chevauchements évidents (activités exclusives entre elles et avec les pauses). */
    private function assertNoOverlaps(array $activities, array $breaks): void
    {
        $exclusive = array_values(array_filter($activities, fn ($a) => ! $a['non_exclusive']));
        $n = count($exclusive);
        for ($x = 0; $x < $n; $x++) {
            $ax = $exclusive[$x];
            for ($y = $x + 1; $y < $n; $y++) {
                if ($this->overlap($ax, $exclusive[$y])) {
                    throw new \DomainException('Two exclusive activities overlap in the composition.');
                }
            }
            foreach ($breaks as $b) {
                if ($this->overlap($ax, $b)) {
                    throw new \DomainException('An activity overlaps a planned break.');
                }
            }
        }
    }

    private function overlap(array $a, array $b): bool
    {
        $aStart = $a['offset_minutes'];
        $aEnd = $a['offset_minutes'] + $a['duration_minutes'];
        $bStart = $b['offset_minutes'];
        $bEnd = $b['offset_minutes'] + $b['duration_minutes'];

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    private function durationMinutes(string $startTime, string $endTime): int
    {
        $start = $this->toMinutes($startTime);
        $end = $this->toMinutes($endTime);
        $dur = $end - $start;
        if ($dur <= 0) {
            $dur += 1440; // shift traversant minuit
        }

        return $dur;
    }

    private function toMinutes(string $t): int
    {
        $p = explode(':', $t);

        return ((int) ($p[0] ?? 0)) * 60 + (int) ($p[1] ?? 0);
    }
}
