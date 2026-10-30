<?php

namespace App\Core\Crypto;

/**
 * W-SIGNATURE-4 (ADR-656) — vérification des signatures PERSONNELLES contrôlées par le signataire.
 *
 * Le signataire génère une paire de clés DANS son navigateur (WebCrypto, ECDSA P-256, clé privée
 * NON extractible, conservée en IndexedDB). Il signe localement une représentation CANONIQUE liée au
 * document + à la demande + à un challenge à usage unique. Le BACKEND vérifie la signature contre la
 * clé PUBLIQUE enregistrée — la clé privée ne quitte JAMAIS l'appareil et n'est jamais transmise à Leezr.
 *
 * MODÈLE DE CONFIANCE : ceci est la signature PERSONNELLE du signataire (distincte du cachet
 * organisationnel SERVEUR Leezr). Elle N'EST PAS une signature avancée/qualifiée eIDAS tant que
 * l'identité n'est pas liée par une vérification forte + une chaîne de confiance reconnue.
 *
 * SÉCURITÉ : openssl uniquement (pas d'algo maison). WebCrypto ECDSA = signature P1363 (r‖s) ;
 * openssl attend du DER → conversion P1363→DER avant vérification.
 */
class PersonalSignatureService
{
    public const DOMAIN = 'leezr.signature.personal.v1';
    public const ALGORITHM = 'ECDSA-P256-SHA256';

    /**
     * Représentation CANONIQUE des données signées (séparation de domaine explicite).
     * Le client DOIT signer exactement ces octets.
     */
    public function canonicalPayload(array $claims): string
    {
        // Ordre de clés déterministe + séparation de domaine → pas d'ambiguïté sur les données signées.
        $ordered = [
            'domain' => self::DOMAIN,
            'company_id' => (int) ($claims['company_id'] ?? 0),
            'request_id' => (int) ($claims['request_id'] ?? 0),
            'signer_id' => (int) ($claims['signer_id'] ?? 0),
            'signer_email' => (string) ($claims['signer_email'] ?? ''),
            'document_sha256' => (string) ($claims['document_sha256'] ?? ''),
            'challenge' => (string) ($claims['challenge'] ?? ''),
        ];

        return json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Identifiant stable d'une clé publique (empreinte SHA-256 du SPKI DER). */
    public function keyId(string $spkiDerBase64): string
    {
        $der = base64_decode($spkiDerBase64, true) ?: '';

        return hash('sha256', $der);
    }

    /** Convertit un SPKI (DER base64, export WebCrypto) en PEM pour openssl. */
    public function spkiToPem(string $spkiDerBase64): string
    {
        $b64 = chunk_split(preg_replace('/\s+/', '', $spkiDerBase64), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$b64}-----END PUBLIC KEY-----\n";
    }

    /**
     * Vérifie une signature personnelle (P1363 base64) sur des octets canoniques, avec la clé publique.
     */
    public function verify(string $spkiDerBase64, string $canonicalBytes, string $signatureP1363Base64): bool
    {
        $pubPem = $this->spkiToPem($spkiDerBase64);
        $pub = openssl_pkey_get_public($pubPem);
        if ($pub === false) {
            return false;
        }

        $raw = base64_decode($signatureP1363Base64, true);
        if ($raw === false || strlen($raw) !== 64) { // P-256 : r (32) + s (32)
            return false;
        }
        $der = $this->p1363ToDer($raw);

        $ok = openssl_verify($canonicalBytes, $der, $pub, OPENSSL_ALGO_SHA256);

        return $ok === 1;
    }

    /** Conversion P1363 (r‖s, 64 octets) → ECDSA-Sig-Value DER. */
    private function p1363ToDer(string $raw): string
    {
        $r = substr($raw, 0, 32);
        $s = substr($raw, 32, 32);

        $rEnc = $this->derInteger($r);
        $sEnc = $this->derInteger($s);
        $seq = $rEnc . $sEnc;

        return "\x30" . $this->derLength(strlen($seq)) . $seq;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) { // bit de poids fort → ajouter 0x00 pour rester positif
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xFF) . $bytes;
            $len >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
