<?php

namespace App\Core\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ADR-632 (M0) — interprétation SÛRE des bornes de période historiques (V1) vers la convention
 * cible semi-ouverte `[start, end)` à minuit UTC pour V2.
 *
 * Pur, déterministe, SANS lecture DB et SANS écriture. En M0 ce helper n'est branché à aucun chemin
 * du moteur ; il sert à l'affichage/analytique et à préparer la transition V1→V2 (M-transition).
 *
 * Décisions métier figées (ADR-632) :
 *   1. UTC = fuseau de référence V2.
 *   2. Les abonnements à anniversaire non-minuit CONSERVENT leur heure contractuelle (instant exact).
 *   4. Le prorata des cycles à anniversaire doit rester cohérent avec ces instants — ce helper NE force
 *      PAS un calcul en jours civils entiers pour un anniversaire (il préserve l'instant exclusif).
 *
 * Classification de la borne de fin stockée :
 *   - `inclusive_eod`      : 23:59:59(.x)                 → exclusive = instant suivant (jour suivant 00:00)
 *   - `midnight_exclusive` : 00:00:00                     → déjà exclusive, inchangée
 *   - `anniversary`        : heure(fin) == heure(début) ≠ minuit → instant contractuel, inchangé
 *   - `ambiguous`          : aucun des cas                → NON RÉSOLU (end_exclusive = null), revue manuelle
 *
 * Une période ambiguë n'est JAMAIS interprétée arbitrairement pour produire une charge financière.
 */
final class PeriodBounds
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly ?CarbonImmutable $endExclusive,
        public readonly string $classification,
        public readonly bool $resolved,
    ) {}

    /**
     * Interprète une période historique (bornes potentiellement incohérentes) en UTC.
     */
    public static function fromLegacy(CarbonInterface $start, CarbonInterface $end): self
    {
        $start = CarbonImmutable::parse($start)->utc();
        $end = CarbonImmutable::parse($end)->utc();

        // Fin de journée inclusive (dernière seconde) → borne exclusive = instant suivant.
        if ($end->format('H:i:s') === '23:59:59') {
            return new self($start, $end->addSecond(), 'inclusive_eod', true);
        }

        // Minuit → déjà exclusive (convention semi-ouverte).
        if ($end->format('H:i:s') === '00:00:00') {
            return new self($start, $end, 'midnight_exclusive', true);
        }

        // Anniversaire à heure précise (fin à la même heure que le début) → instant contractuel exclusif.
        if ($end->format('H:i:s') === $start->format('H:i:s')) {
            return new self($start, $end, 'anniversary', true);
        }

        // Sémantique inconnue → NON résolu, revue manuelle. Aucune charge dérivée.
        return new self($start, null, 'ambiguous', false);
    }

    /**
     * Durée en jours ENTIERS d'une période résolue. Uniquement pour les cycles calendaires
     * (inclusive_eod / midnight_exclusive) ; pour un anniversaire la durée exacte peut être
     * fractionnaire (instants), à ne PAS arrondir en jours civils pour le prorata (décision #4).
     * Retourne null si non résolu.
     */
    public function calendarDays(): ?int
    {
        if (! $this->resolved || $this->endExclusive === null) {
            return null;
        }

        return (int) $this->start->diffInDays($this->endExclusive);
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
