<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\DTOs\ContributionResult;

/**
 * Calcul du Montant Net Social (MNS) — obligatoire sur le bulletin de paie depuis 01/01/2024.
 *
 * Formule définitive (arrêté du 31 janvier 2023, modifié 25 juin 2024) :
 *   Net Social = Brut
 *              - Cotisations salariales obligatoires (toutes)
 *              + CSG non déductible (ré-ajoutée — prélèvement fiscal, pas social)
 *              + CRDS (ré-ajoutée — prélèvement fiscal, pas social)
 *
 * Ce service est pur (aucun accès DB, aucun side-effect).
 * Le MNS est un résultat d'affichage bulletin/social, pas une source de vérité payroll.
 */
class NetSocialCalculator
{
    /**
     * @param  int  $grossTotalCents  Salaire brut total (incluant avantages imposables)
     * @param  ContributionResult  $contributions  Résultat des cotisations (avec lignes détaillées)
     * @return int Montant net social en centimes
     */
    public static function compute(int $grossTotalCents, ContributionResult $contributions): int
    {
        $crdsCents = self::extractCrds($contributions);

        // Net social = Brut - cotisations salariales + CSG non-déductible + CRDS
        return $grossTotalCents
            - $contributions->totalEmployeeCents
            + $contributions->csgNonDeductibleCents
            + $crdsCents;
    }

    /**
     * Extract CRDS amount from contribution lines (code = 'crds').
     * Returns 0 if no CRDS line found (graceful degradation).
     */
    private static function extractCrds(ContributionResult $contributions): int
    {
        foreach ($contributions->lines as $line) {
            if ($line->code === 'crds') {
                return $line->employeeCents;
            }
        }

        return 0;
    }
}
