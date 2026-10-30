<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Crypto\DevCertificateAuthority;
use App\Core\Crypto\NativePadesSignatureService;
use App\Core\Models\Company;

/**
 * W-SIGNATURE-8 (ADR-660) — catalogue des FORMATS de signature (vocabulaire MÉTIER ↔ format technique).
 *
 * Le métier ne manipule jamais `pades_external` / `pades_embedded` / `cms_detached` : il choisit un
 * MODE compréhensible. La disponibilité d'un mode dépend des capacités RÉELLEMENT vérifiées
 * (environnement, pdfsig, AC interne) ET de l'autorisation Platform par Market — jamais une simple
 * étiquette UI. Une Company ne peut pas sélectionner un mode non autorisé par Platform.
 */
class SignatureFormatCatalog
{
    // Modes métier.
    public const PERSONAL_DIGITAL = 'personal_digital'; // → pades_external (2 signatures personnelles)
    public const DEMONSTRATION = 'demonstration';       // → pades_embedded (cachet serveur, démo/historique)

    public const MODES = [self::PERSONAL_DIGITAL, self::DEMONSTRATION];

    /** Mode métier → format technique (required_format) interne. */
    public static function requiredFormatFor(string $mode): string
    {
        return match ($mode) {
            self::PERSONAL_DIGITAL => 'pades_external',
            default => 'pades_embedded',
        };
    }

    /** Autorisation Platform par Market (donnée de configuration, cohérente architecture modulaire). */
    public static function platformAllowsForMarket(string $marketKey, string $mode): bool
    {
        $allowed = config('signature.platform.markets.' . $marketKey . '.formats');
        if (! is_array($allowed)) {
            // Défaut : hors PROD, tous les modes DEV sont autorisés ; en PROD, aucun mode personnel.
            $allowed = app()->environment('production') ? [self::DEMONSTRATION] : self::MODES;
        }

        return in_array($mode, $allowed, true);
    }

    /**
     * Modes disponibles pour une Company, avec disponibilité réelle et motif d'indisponibilité.
     *
     * @return array<int,array{mode:string, required_format:string, available:bool, reason:?string,
     *               personal_pdf_signature:bool, trust:string}>
     */
    public static function describe(Company $company): array
    {
        $market = $company->market_key ?? 'FR';
        $pdfsig = app(NativePadesSignatureService::class)->pdfsigAvailable();
        $caOk = app(DevCertificateAuthority::class)->issuanceEnabled();
        $out = [];

        // Signature personnelle numérique (pades_external).
        $reason = null;
        if (! self::platformAllowsForMarket($market, self::PERSONAL_DIGITAL)) {
            $reason = 'not_allowed_by_platform';
        } elseif (! $pdfsig) {
            $reason = 'pdfsig_unavailable';
        } elseif (! $caOk) {
            $reason = 'certificate_authority_unavailable';
        }
        $out[] = [
            'mode' => self::PERSONAL_DIGITAL,
            'required_format' => 'pades_external',
            'available' => $reason === null,
            'reason' => $reason,
            'personal_pdf_signature' => true,
            'trust' => 'internal_non_recognized', // certs internes non reconnus, identité déclarée
        ];

        // Signature de démonstration (cachet serveur embarqué).
        $reasonD = null;
        if (! self::platformAllowsForMarket($market, self::DEMONSTRATION)) {
            $reasonD = 'not_allowed_by_platform';
        } elseif (! $pdfsig) {
            $reasonD = 'pdfsig_unavailable';
        }
        $out[] = [
            'mode' => self::DEMONSTRATION,
            'required_format' => 'pades_embedded',
            'available' => $reasonD === null,
            'reason' => $reasonD,
            'personal_pdf_signature' => false,
            'trust' => 'organizational_server_seal',
        ];

        return $out;
    }

    /** Un mode est-il réellement disponible pour cette Company ? */
    public static function isAvailable(Company $company, string $mode): bool
    {
        foreach (self::describe($company) as $m) {
            if ($m['mode'] === $mode) {
                return $m['available'];
            }
        }

        return false;
    }

    /**
     * Mode par défaut applicable. Choix SÛR et rétro-compatible : la démonstration (cachet serveur)
     * reste le défaut ; la signature PERSONNELLE numérique est un choix EXPLICITE de la Company
     * (elle engage l'émission de certificats internes). Si la démonstration est indisponible mais le
     * mode personnel l'est, on retombe sur ce dernier.
     */
    public static function defaultMode(Company $company): string
    {
        if (self::isAvailable($company, self::DEMONSTRATION)) {
            return self::DEMONSTRATION;
        }
        if (self::isAvailable($company, self::PERSONAL_DIGITAL)) {
            return self::PERSONAL_DIGITAL;
        }

        return self::DEMONSTRATION;
    }
}
