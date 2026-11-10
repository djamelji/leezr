<?php

namespace App\Core\Documents;

/**
 * ADR-756 (DMS M0) — classification de confidentialité des documents. Définie dès M0 (même si
 * le pipeline d'import de bulletins sera développé plus tard) : un bulletin de paie et les
 * pièces sensibles sont HAUTEMENT confidentiels → accès gated au-delà du simple droit de vue.
 */
class DocumentConfidentiality
{
    public const PUBLIC = 'public';
    public const INTERNAL = 'internal';
    public const CONFIDENTIAL = 'confidential';
    public const HIGHLY_CONFIDENTIAL = 'highly_confidential';

    /** Codes de types hautement confidentiels (bulletins, pièces d'identité, bancaires, judiciaires). */
    private const HIGHLY = [
        'payslip', 'payslip_draft_fr', 'payslip_official_fr',
        'rib', 'social_security', 'id_card', 'criminal_record',
    ];

    public static function forTypeCode(?string $code): string
    {
        if ($code !== null && in_array($code, self::HIGHLY, true)) {
            return self::HIGHLY_CONFIDENTIAL;
        }

        // Par défaut, les documents RH/personnels sont confidentiels (jamais publics).
        return self::CONFIDENTIAL;
    }

    public static function isHighlyConfidential(?string $code): bool
    {
        return self::forTypeCode($code) === self::HIGHLY_CONFIDENTIAL;
    }
}
