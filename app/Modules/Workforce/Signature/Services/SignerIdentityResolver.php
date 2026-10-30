<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Core\Workforce\Employee;
use App\Modules\Workforce\Signature\Models\SignatureSigner;

/**
 * W-SIGNATURE-9 (ADR-661) — résolution de l'IDENTITÉ attendue d'un signataire et du niveau d'assurance.
 *
 * Sépare strictement « possession du lien » de « identité authentifiée ». Un signataire INTERNE est
 * rattaché à un compte Leezr (via party_type user, ou via l'employé → son compte utilisateur). Un
 * signataire EXTERNE n'a pas de compte : son identité reste DÉCLARÉE (email), jamais certifiée.
 *
 * Niveaux d'assurance (honnêtes) :
 *   - account_authenticated : le compte Leezr ATTENDU est authentifié (session) et correspond au signataire.
 *   - link_only            : signataire interne, mais seule la possession du lien est établie (pas d'auth).
 *   - declared             : signataire externe — identité déclarée par email, non authentifiée.
 */
class SignerIdentityResolver
{
    public const ASSURANCE_ACCOUNT = 'account_authenticated';
    public const ASSURANCE_LINK = 'link_only';
    public const ASSURANCE_DECLARED = 'declared';

    /** Compte utilisateur ATTENDU pour ce signataire, ou null s'il est externe (sans compte). */
    public function expectedUserId(SignatureSigner $signer): ?int
    {
        if ($signer->party_type === 'user' && $signer->party_id) {
            return (int) $signer->party_id;
        }
        if ($signer->party_type === 'employee' && $signer->party_id) {
            $uid = Employee::withoutGlobalScopes()->find($signer->party_id)?->user_id;

            return $uid ? (int) $uid : null;
        }

        return null;
    }

    /** Le signataire est-il rattaché à un compte Leezr (interne) ? */
    public function isInternal(SignatureSigner $signer): bool
    {
        return $this->expectedUserId($signer) !== null;
    }

    /** L'utilisateur authentifié correspond-il au compte attendu du signataire ? */
    public function authenticatedMatches(SignatureSigner $signer, ?int $authUserId): bool
    {
        $expected = $this->expectedUserId($signer);

        return $expected !== null && $authUserId !== null && $authUserId === $expected;
    }

    /** Niveau d'assurance réellement obtenu pour (signataire, utilisateur authentifié). */
    public function assuranceFor(SignatureSigner $signer, ?int $authUserId): string
    {
        if ($this->authenticatedMatches($signer, $authUserId)) {
            return self::ASSURANCE_ACCOUNT;
        }
        if ($this->expectedUserId($signer) === null) {
            return self::ASSURANCE_DECLARED; // externe : identité déclarée (email)
        }

        return self::ASSURANCE_LINK;         // interne non authentifié : possession du lien seule
    }
}
