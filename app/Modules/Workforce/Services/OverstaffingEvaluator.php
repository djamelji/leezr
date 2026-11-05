<?php

namespace App\Modules\Workforce\Services;

use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use Illuminate\Support\Facades\Hash;

/**
 * Évaluation du SUREFFECTIF marginal d'une affectation envisagée (Smart Planning Lot 4B.1, ADR-719).
 *
 * Réutilise CoverageReadModel (contribution QUALIFIÉE par sous-intervalles, catégorie/lieu/Department,
 * absences complètes/partielles, pauses, activités non productives, DISTINCT Employee). Compare la
 * couverture « SANS » vs « AVEC » le shift prospectif → ne signale que l'IMPACT MARGINAL RÉEL :
 *  - un sureffectif PRÉEXISTANT (sans contribution supplémentaire) ne déclenche PAS d'alerte ;
 *  - une affectation non contributive (ex. Formation sur un besoin Route) ne déclenche PAS d'alerte ;
 *  - absence de CoverageRequirement → aucun besoin implicite à zéro, aucun sureffectif.
 *
 * La SIGNATURE (HMAC) fige l'impact exact : si l'état change entre prévisualisation et confirmation
 * (concurrence), la signature recalculée diffère → nouvelle confirmation exigée.
 */
class OverstaffingEvaluator
{
    /**
     * @param  array{employee_id:int,date:string,start_at:string,end_at:string,work_location_id:?int,template_snapshot:?array,ignore_shift_id:?int}  $prospect
     * @return array{overstaffed:bool, impacts:array<int,array<string,mixed>>, signature:string}
     */
    public function evaluate(int $companyId, array $prospect): array
    {
        $date = substr($prospect['date'], 0, 10);

        // Shift prospectif (non persisté) injecté dans le balayage.
        $extra = new Shift;
        $extra->forceFill([
            'id' => null,
            'company_id' => $companyId,
            'employee_id' => $prospect['employee_id'],
            'date' => $date,
            'start_at' => $prospect['start_at'],
            'end_at' => $prospect['end_at'],
            'work_location_id' => $prospect['work_location_id'] ?? null,
            'template_snapshot' => $prospect['template_snapshot'] ?? [],
            'status' => Shift::STATUS_DRAFT,
        ]);

        // Pour un DÉPLACEMENT : on exclut l'ancienne position du shift des DEUX calculs (sans/avec),
        // afin de ne mesurer que l'impact marginal de la NOUVELLE position.
        $ignore = ! empty($prospect['ignore_shift_id']) ? [(int) $prospect['ignore_shift_id']] : [];

        $without = CoverageReadModel::forRange($companyId, $date, $date, [], $ignore);
        $with = CoverageReadModel::forRange($companyId, $date, $date, [$extra], $ignore);

        $impacts = [];
        foreach ($with['requirements'] as $wReq) {
            // Un déplacement remplace l'ancien shift : on compare au « sans » qui l'exclut déjà (géré en amont).
            $woReq = collect($without['requirements'])->first(fn ($r) => $r['requirement_id'] === $wReq['requirement_id'] && $r['date'] === $wReq['date']);
            if (! $woReq) {
                continue;
            }
            $headcount = (int) $wReq['headcount'];

            foreach ($wReq['intervals'] as $iv) {
                $assignedWith = (int) $iv['assigned'];
                if ($assignedWith <= $headcount) {
                    continue; // pas de dépassement sur cet intervalle
                }
                $assignedWithout = $this->assignedAt($woReq['intervals'], $iv['start']);
                if ($assignedWith <= $assignedWithout) {
                    continue; // sureffectif PRÉEXISTANT, aucune contribution marginale → pas d'alerte
                }
                // Dépassement marginal réel sur [start,end].
                $impacts[$wReq['requirement_id']] = [
                    'requirement_id' => $wReq['requirement_id'],
                    'label' => $wReq['label'],
                    'category_name' => $wReq['category_name'],
                    'coverage_category_id' => $wReq['coverage_category_id'],
                    'department_id' => $wReq['department_id'],
                    'work_location_id' => $wReq['work_location_id'],
                    'date' => $wReq['date'],
                    'window' => ['start' => $iv['start'], 'end' => $iv['end']],
                    'required' => $headcount,
                    'projected' => $assignedWith,
                ];
            }
        }

        $impacts = array_values($impacts);

        return [
            'overstaffed' => count($impacts) > 0,
            'impacts' => $impacts,
            'signature' => $this->sign($companyId, $impacts),
        ];
    }

    /** Effectif « sans » au temps $start (intervalle couvrant ce début). */
    private function assignedAt(array $intervals, string $start): int
    {
        foreach ($intervals as $iv) {
            if ($start >= $iv['start'] && $start < $iv['end']) {
                return (int) $iv['assigned'];
            }
        }
        // Bord exact de fin : retombe sur le dernier intervalle commençant à $start.
        foreach ($intervals as $iv) {
            if ($iv['start'] === $start) {
                return (int) $iv['assigned'];
            }
        }

        return 0;
    }

    /** Signature serveur liant la confirmation à l'impact EXACT (anti-contournement / anti-concurrence). */
    public function sign(int $companyId, array $impacts): string
    {
        $canonical = collect($impacts)
            ->map(fn ($i) => "{$i['requirement_id']}|{$i['date']}|{$i['window']['start']}-{$i['window']['end']}|{$i['required']}|{$i['projected']}")
            ->sort()->values()->implode(';');

        return hash_hmac('sha256', $companyId.'::'.$canonical, (string) config('app.key'));
    }
}
