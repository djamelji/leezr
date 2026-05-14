<?php

namespace App\Core\Workforce;

/**
 * Groupes de cotisations du bulletin de paie clarifié FR.
 * Conforme à l'arrêté du 25 février 2016 modifié (modèle transitoire jusqu'au 31/12/2026).
 *
 * Ces groupes sont purement informatifs (template rendering) et n'affectent pas le calcul.
 */
final class BulletinGroup
{
    const SANTE = 'sante';
    const AT_MP = 'at_mp';
    const RETRAITE = 'retraite';
    const FAMILLE = 'famille';
    const CHOMAGE = 'chomage';
    const CSG_CRDS = 'csg_crds';
    const AUTRES_EMPLOYEUR = 'autres_employeur';

    /**
     * Ordered list of bulletin groups as they appear on the official payslip.
     */
    const DISPLAY_ORDER = [
        self::SANTE,
        self::AT_MP,
        self::RETRAITE,
        self::FAMILLE,
        self::CHOMAGE,
        self::CSG_CRDS,
        self::AUTRES_EMPLOYEUR,
    ];

    /**
     * French labels for each bulletin group.
     */
    const LABELS = [
        self::SANTE => 'Santé',
        self::AT_MP => 'Accidents du travail / Maladies professionnelles',
        self::RETRAITE => 'Retraite',
        self::FAMILLE => 'Famille',
        self::CHOMAGE => 'Assurance chômage',
        self::CSG_CRDS => 'CSG / CRDS',
        self::AUTRES_EMPLOYEUR => 'Autres contributions dues par l\'employeur',
    ];

    public static function isValid(string $group): bool
    {
        return in_array($group, self::DISPLAY_ORDER, true);
    }

    public static function label(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }
}
