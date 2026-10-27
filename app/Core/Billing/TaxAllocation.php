<?php

namespace App\Core\Billing;

/**
 * ADR-632 (M7) — répartition DÉTERMINISTE de la TVA AGRÉGÉE de la facture (cible = invoice.tax_amount,
 * la vérité comptable, ADR-632 M4) entre ses lignes, proportionnellement au HT, par la méthode du plus
 * grand reste. Garantit Σ(TVA par ligne) == TVA agrégée AU CENTIME, sans taux global artificiel et sans
 * recalcul divergent. Les lignes à HT <= 0 (remises) ne portent pas de TVA positive.
 *
 * Note fiscale (ADR-632) : une facture = un contexte fiscal (un client) → un taux/une catégorie ; les
 * cas multi-régimes (reverse-charge/export) produisent des DOCUMENTS séparés, chacun avec sa cible.
 *
 * @param array<int,int> $lineHts  HT en cents, indexé par id de ligne
 * @return array<int,int> TVA en cents par id de ligne (somme == $aggregateTax)
 */
class TaxAllocation
{
    public static function allocate(array $lineHts, int $aggregateTax): array
    {
        $out = array_fill_keys(array_keys($lineHts), 0);
        if ($aggregateTax <= 0) {
            return $out;
        }

        $taxableBase = array_sum(array_map(fn ($ht) => max(0, $ht), $lineHts));
        if ($taxableBase <= 0) {
            return $out;
        }

        $remainders = [];
        $distributed = 0;
        foreach ($lineHts as $id => $ht) {
            $ht = max(0, $ht);
            $exact = $ht / $taxableBase * $aggregateTax;
            $out[$id] = (int) floor($exact);
            $remainders[$id] = $exact - $out[$id];
            $distributed += $out[$id];
        }

        $remaining = $aggregateTax - $distributed; // centimes restants (>= 0)
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b); // stable
        foreach ($order as $id) {
            if ($remaining <= 0) {
                break;
            }
            $out[$id]++;
            $remaining--;
        }

        return $out;
    }
}
