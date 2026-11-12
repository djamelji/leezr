<?php

namespace App\Core\Documents\Payslip;

/**
 * ADR-763 (DMS M5.1) — découpage FIABLE d'une liasse en segments (bulletins). Règles déterministes :
 *  - des pages CONSÉCUTIVES partageant le même matricule forment UN bulletin multipage ;
 *  - un changement de matricule ouvre un nouveau bulletin ;
 *  - une page SANS matricule se rattache au bulletin précédent (annexe) ; sans bulletin précédent,
 *    elle reste en QUARANTAINE (segment isolé, confiance none → revue) ;
 *  - une page blanche est ignorée des preuves mais conservée dans le segment courant.
 *
 * Aucune page isolée n'est publiée sans appartenance déterminée. Les cas réellement ambigus
 * (ex. première page sans matricule) restent en revue.
 *
 * @param  list<array{page_no:int,matricule:?string,period:?string,is_blank:bool,source:string}>  $pages
 * @return list<array{pages:list<int>,source:string,matricule:?string,period:?string,has_annex:bool,needs_review:bool}>
 */
class PayslipSegmenter
{
    public function segment(array $pages): array
    {
        $segments = [];
        $current = null;

        foreach ($pages as $p) {
            $matricule = $p['matricule'] ?: null;

            if ($matricule !== null) {
                // Nouveau bulletin si aucun segment courant OU matricule différent.
                if ($current === null || $current['matricule'] !== $matricule || $current['source'] !== $p['source']) {
                    if ($current !== null) {
                        $segments[] = $current;
                    }
                    $current = [
                        'pages' => [$p['page_no']],
                        'source' => $p['source'],
                        'matricule' => $matricule,
                        'period' => $p['period'] ?: null,
                        'has_annex' => false,
                        'needs_review' => false,
                    ];
                } else {
                    // Même matricule, même source, consécutif → page supplémentaire du bulletin.
                    $current['pages'][] = $p['page_no'];
                    $current['period'] = $current['period'] ?: ($p['period'] ?: null);
                }

                continue;
            }

            // Page sans matricule.
            if ($current !== null && $current['source'] === $p['source']) {
                // Annexe du bulletin précédent.
                $current['pages'][] = $p['page_no'];
                $current['has_annex'] = true;
            } else {
                // Aucun bulletin de rattachement → quarantaine (revue obligatoire).
                if ($current !== null) {
                    $segments[] = $current;
                    $current = null;
                }
                $segments[] = [
                    'pages' => [$p['page_no']],
                    'source' => $p['source'],
                    'matricule' => null,
                    'period' => $p['period'] ?: null,
                    'has_annex' => false,
                    'needs_review' => true,
                ];
            }
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }
}
