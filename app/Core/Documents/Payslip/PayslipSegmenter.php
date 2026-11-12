<?php

namespace App\Core\Documents\Payslip;

/**
 * ADR-763 / ADR-764 (DMS M5.1/M5.2) — découpage FIABLE d'une liasse. Règles déterministes :
 *  - des pages CONSÉCUTIVES partageant le même matricule forment UN bulletin multipage ;
 *  - un changement de matricule ouvre un nouveau bulletin ;
 *  - une page SANS matricule n'est rattachée QUE sur PREUVE concordante (numérotation continue
 *    "k/n" → "k+1/n") ; sinon elle est rattachée MAIS le segment passe en REVUE (composition
 *    incertaine, raison expliquée) ; sans bulletin précédent → QUARANTAINE isolée.
 *
 * La continuité physique seule n'est JAMAIS une preuve de rattachement. Un segment incertain n'est
 * pas publiable automatiquement.
 *
 * @param  list<array{page_no:int,matricule:?string,period:?string,is_blank:bool,source:string,page_index:?int,page_total:?int}>  $pages
 * @return list<array{pages:list<int>,source:string,matricule:?string,period:?string,has_annex:bool,needs_review:bool,reason:?string}>
 */
class PayslipSegmenter
{
    public function segment(array $pages): array
    {
        $segments = [];
        $current = null;

        foreach ($pages as $p) {
            $matricule = $p['matricule'] ?: null;
            $idx = $p['page_index'] ?? null;
            $total = $p['page_total'] ?? null;

            if ($matricule !== null) {
                if ($current === null || $current['matricule'] !== $matricule || $current['source'] !== $p['source']) {
                    if ($current !== null) {
                        $segments[] = $current;
                    }
                    $current = [
                        'pages' => [$p['page_no']], 'source' => $p['source'], 'matricule' => $matricule,
                        'period' => $p['period'] ?: null, 'has_annex' => false, 'needs_review' => false,
                        'reason' => null, 'last_index' => $idx, 'page_total' => $total,
                    ];
                } else {
                    // Même matricule → page supplémentaire du même bulletin (fiable).
                    $current['pages'][] = $p['page_no'];
                    $current['period'] = $current['period'] ?: ($p['period'] ?: null);
                    $current['last_index'] = $idx ?? $current['last_index'];
                    $current['page_total'] = $current['page_total'] ?? $total;
                }

                continue;
            }

            // Page SANS matricule.
            if ($current !== null && $current['source'] === $p['source']) {
                $continues = $idx !== null && $current['last_index'] !== null
                    && $idx === $current['last_index'] + 1
                    && ($current['page_total'] === null || $total === null || $total === $current['page_total']);

                $current['pages'][] = $p['page_no'];
                $current['has_annex'] = true;
                if ($continues) {
                    // Preuve de continuité → rattachement fiable.
                    $current['last_index'] = $idx;
                    $current['page_total'] = $current['page_total'] ?? $total;
                } else {
                    // Rattachement INCERTAIN → le segment passe en revue (raison expliquée).
                    $current['needs_review'] = true;
                    $current['reason'] = 'no_matricule_page';
                }
            } else {
                // Aucun bulletin de rattachement → quarantaine isolée.
                if ($current !== null) {
                    $segments[] = $current;
                    $current = null;
                }
                $segments[] = [
                    'pages' => [$p['page_no']], 'source' => $p['source'], 'matricule' => null,
                    'period' => $p['period'] ?: null, 'has_annex' => false, 'needs_review' => true,
                    'reason' => 'isolated_page', 'last_index' => $idx, 'page_total' => $total,
                ];
            }
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }
}
