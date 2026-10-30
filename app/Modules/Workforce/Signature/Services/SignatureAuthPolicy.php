<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Models\Company;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSigner;

/**
 * W-SIGNATURE-10 (ADR-662) — politique d'AUTHENTIFICATION exigée pour signer, pilotée par Platform/Market.
 *
 * Détermine le NIVEAU D'ASSURANCE minimal qu'un signataire doit atteindre AVANT la première émission de
 * certificat et la signature. Les règles vivent dans la configuration Market (données), pas en `if('FR')`
 * dispersés. La Company choisit uniquement parmi les options autorisées ; un réglage n'attribue JAMAIS un
 * niveau eIDAS avancé/qualifié.
 *
 * Défaut (sain) :
 *   - signataire INTERNE (compte Leezr attendu) → `account_authenticated` (le lien seul NE SUFFIT PAS) ;
 *   - signataire EXTERNE (sans compte)         → `declared` (identité e-mail, usages limités).
 * Le Market peut RENFORCER (ex. exiger `account_mfa` pour les internes) ; jamais affaiblir sous le défaut.
 */
class SignatureAuthPolicy
{
    public function __construct(private readonly SignerIdentityResolver $identity) {}

    /** Niveau d'assurance minimal EXIGÉ pour ce signataire. */
    public function requiredAssurance(SignatureRequest $request, SignatureSigner $signer, ?Company $company = null): string
    {
        $market = $company?->market_key ?? 'FR';
        $isInternal = $this->identity->isInternal($signer);

        // Défaut sain non contournable vers le bas.
        $base = $isInternal ? SignerIdentityResolver::ASSURANCE_ACCOUNT : SignerIdentityResolver::ASSURANCE_DECLARED;

        // Renforcement possible par Market (ex. 'account_mfa' pour les internes).
        $marketMin = config('signature.platform.markets.' . $market . '.min_assurance.' . ($isInternal ? 'internal' : 'external'));
        // Renforcement possible par document (metadata required_assurance).
        $docMin = $request->metadata['required_assurance'] ?? null;

        $candidates = array_filter([$base, $marketMin, $docMin], fn ($a) => is_string($a) && isset(SignerIdentityResolver::ASSURANCE_RANK[$a]));

        // Le plus EXIGEANT l'emporte, borné au défaut comme plancher.
        return collect($candidates)->sortByDesc(fn ($a) => SignerIdentityResolver::rank($a))->first() ?? $base;
    }

    /** L'assurance obtenue satisfait-elle l'exigence ? */
    public function meets(string $obtained, string $required): bool
    {
        return SignerIdentityResolver::rank($obtained) >= SignerIdentityResolver::rank($required);
    }

    /** Message métier lorsqu'une authentification plus forte est requise. */
    public function requirementMessage(string $required): string
    {
        return match ($required) {
            SignerIdentityResolver::ASSURANCE_MFA => 'Une authentification renforcée (2FA) de votre compte Leezr est requise pour signer ce document.',
            SignerIdentityResolver::ASSURANCE_ACCOUNT => 'L\'authentification de votre compte Leezr est requise pour signer ce document (le lien d\'invitation ne suffit pas).',
            default => 'Une authentification supplémentaire est requise pour signer ce document.',
        };
    }
}
