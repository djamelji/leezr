<?php

namespace App\Core\Documents;

/**
 * ADR-756 (M0) / ADR-757 (M1) / ADR-758 (M1.1) — classification de confidentialité des documents.
 *
 * Deux dimensions :
 *  - un NIVEAU (public/internal/confidential/highly_confidential) ;
 *  - une CATÉGORIE pour les pièces hautement confidentielles (payroll vs identity_financial),
 *    afin que `documents.view_confidential` ne soit PAS un passe universel : les bulletins (payroll)
 *    relèvent du domaine paie/rémunération, l'identité/bancaire/judiciaire d'un droit RH sensible.
 */
class DocumentConfidentiality
{
    // Niveaux
    public const PUBLIC = 'public';
    public const INTERNAL = 'internal';
    public const CONFIDENTIAL = 'confidential';
    public const HIGHLY_CONFIDENTIAL = 'highly_confidential';

    // Catégories (pour les pièces hautement confidentielles)
    public const CAT_STANDARD = 'standard';                 // confidentiel « simple »
    public const CAT_PAYROLL = 'payroll';                   // bulletins de paie
    public const CAT_IDENTITY_FINANCIAL = 'identity_financial'; // ID, bancaire, social, judiciaire

    /** Bulletins de paie. */
    private const PAYROLL = ['payslip', 'payslip_draft_fr', 'payslip_official_fr'];

    /** Pièces d'identité, bancaires, sociales, judiciaires. */
    private const IDENTITY_FINANCIAL = ['id_card', 'rib', 'social_security', 'criminal_record'];

    public static function forTypeCode(?string $code): string
    {
        if (self::category($code) !== self::CAT_STANDARD) {
            return self::HIGHLY_CONFIDENTIAL;
        }

        // Par défaut, les documents RH/personnels sont confidentiels (jamais publics).
        return self::CONFIDENTIAL;
    }

    public static function category(?string $code): string
    {
        if ($code !== null && in_array($code, self::PAYROLL, true)) {
            return self::CAT_PAYROLL;
        }
        if ($code !== null && in_array($code, self::IDENTITY_FINANCIAL, true)) {
            return self::CAT_IDENTITY_FINANCIAL;
        }

        return self::CAT_STANDARD;
    }

    public static function isHighlyConfidential(?string $code): bool
    {
        return self::forTypeCode($code) === self::HIGHLY_CONFIDENTIAL;
    }
}
