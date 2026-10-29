<?php

namespace App\Core\Crypto;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * W-SIGNATURE-2 (ADR-654) — cœur cryptographique RÉEL et vérifiable, réutilisable (hors Workforce).
 *
 * Produit une signature CMS/PKCS#7 DÉTACHÉE (X.509) sur les octets d'un document, via OpenSSL —
 * PAS d'algorithme maison. Vérifiable INDÉPENDAMMENT (openssl_cms_verify + openssl CLI).
 *
 * MODÈLE DE CONFIANCE (honnête) : la clé est un **cachet organisationnel Leezr** contrôlé par le
 * SERVEUR (auto-signé, DEV) — ce N'EST PAS une signature contrôlée par le signataire, ni une
 * signature avancée/qualifiée eIDAS. Le cachet atteste l'intégrité du document et l'existence du
 * dossier de preuves (consentement + identité), il n'attribue pas à lui seul l'acte au signataire.
 *
 * Sécurité : la clé privée est stockée CHIFFRÉE au repos (Laravel Crypt), jamais en base métier,
 * jamais exposée au frontend, aux logs ou aux réponses API.
 */
class CryptographicSealService
{
    private const DISK = 'local';
    private const CERT_PATH = 'crypto/leezr_seal.crt';
    private const KEY_ENC_PATH = 'crypto/leezr_seal.key.enc';

    /** Garantit l'existence d'un cachet (génère un X.509 auto-signé DEV si absent). */
    public function ensureSeal(): void
    {
        if (Storage::disk(self::DISK)->exists(self::CERT_PATH) && Storage::disk(self::DISK)->exists(self::KEY_ENC_PATH)) {
            return;
        }

        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $dn = [
            'countryName' => 'FR',
            'organizationName' => 'Leezr',
            'organizationalUnitName' => 'Organizational Seal (DEV, self-signed)',
            'commonName' => 'Leezr DEV Organizational Seal',
        ];
        $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $pkey, 3650, ['digest_alg' => 'sha256']); // auto-signé
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem);

        Storage::disk(self::DISK)->put(self::CERT_PATH, $certPem);
        // Clé privée CHIFFRÉE au repos (clé d'app Laravel). Jamais en clair.
        Storage::disk(self::DISK)->put(self::KEY_ENC_PATH, Crypt::encryptString($keyPem));
    }

    /** Certificat public (PEM) du cachet. */
    public function certificatePem(): string
    {
        $this->ensureSeal();

        return Storage::disk(self::DISK)->get(self::CERT_PATH);
    }

    public function certificateInfo(): array
    {
        $parsed = openssl_x509_parse($this->certificatePem()) ?: [];

        return [
            'subject' => $parsed['subject'] ?? [],
            'issuer' => $parsed['issuer'] ?? [],
            'valid_from' => isset($parsed['validFrom_time_t']) ? date('c', $parsed['validFrom_time_t']) : null,
            'valid_to' => isset($parsed['validTo_time_t']) ? date('c', $parsed['validTo_time_t']) : null,
            'self_signed' => ($parsed['subject'] ?? null) === ($parsed['issuer'] ?? null),
            'sha256_fingerprint' => openssl_x509_fingerprint($this->certificatePem(), 'sha256'),
        ];
    }

    /**
     * Cachet cryptographique sur les octets : on signe (CMS/PKCS#7 opaque) l'EMPREINTE SHA-256 du
     * document. Le hash signé est donc lié cryptographiquement au certificat ; toute altération du
     * document ultérieure fait diverger le hash recalculé → échec de vérification.
     * @return array{cms_b64:string, document_sha256:string, algorithm:string, cert_fingerprint:string, sealed_at:string}
     */
    public function seal(string $bytes): array
    {
        $this->ensureSeal();
        $keyPem = Crypt::decryptString(Storage::disk(self::DISK)->get(self::KEY_ENC_PATH));
        $certPem = $this->certificatePem();
        $digest = hash('sha256', $bytes);

        $in = tempnam(sys_get_temp_dir(), 'seal_in_');
        $out = tempnam(sys_get_temp_dir(), 'seal_out_');
        file_put_contents($in, $digest);
        try {
            $ok = openssl_cms_sign(
                $in, $out, $certPem, $keyPem, [],
                OPENSSL_CMS_BINARY, // opaque : le hash est encapsulé dans la structure CMS
                OPENSSL_ENCODING_DER,
            );
            if (! $ok) {
                throw new \RuntimeException('CMS sign failed: '.openssl_error_string());
            }

            return [
                'cms_b64' => base64_encode(file_get_contents($out)),
                'document_sha256' => $digest,
                'algorithm' => 'CMS/PKCS#7 over SHA-256 digest, RSA-2048',
                'cert_fingerprint' => openssl_x509_fingerprint($certPem, 'sha256'),
                'sealed_at' => now()->toIso8601String(),
            ];
        } finally {
            @unlink($in);
            @unlink($out);
            $keyPem = str_repeat("\0", strlen($keyPem));
            unset($keyPem);
        }
    }

    /**
     * Vérifie INDÉPENDAMMENT le cachet : (1) la signature CMS est cryptographiquement valide,
     * (2) le hash encapsulé correspond au SHA-256 RECALCULÉ des octets fournis.
     * @return array{valid:bool, signature_valid:bool, document_intact:bool, signer_subject:?array, detail:string}
     */
    public function verify(string $bytes, string $cmsB64): array
    {
        $der = base64_decode($cmsB64, true);
        if ($der === false) {
            return ['valid' => false, 'signature_valid' => false, 'document_intact' => false, 'signer_subject' => null, 'detail' => 'invalid_signature_encoding'];
        }

        $sig = tempnam(sys_get_temp_dir(), 'ver_sig_');
        $extracted = tempnam(sys_get_temp_dir(), 'ver_ext_');
        $certsOut = tempnam(sys_get_temp_dir(), 'ver_crt_');
        file_put_contents($sig, $der);
        try {
            // NOVERIFY : cachet auto-signé accepté (pas de chaîne reconnue) ; la validité
            // CRYPTOGRAPHIQUE de la signature est bien vérifiée. Le contenu encapsulé (le hash)
            // est extrait dans $extracted.
            $sigValid = openssl_cms_verify(
                $sig,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
                $certsOut,
                [],
                null,
                $extracted,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
            $sealedDigest = is_file($extracted) ? trim((string) file_get_contents($extracted)) : '';
            $currentDigest = hash('sha256', $bytes);
            $intact = $sealedDigest !== '' && hash_equals($sealedDigest, $currentDigest);

            $signer = null;
            if (is_file($certsOut) && ($certPem = file_get_contents($certsOut))) {
                $parsed = openssl_x509_parse($certPem);
                $signer = $parsed['subject'] ?? null;
            }

            return [
                'valid' => (bool) $sigValid && $intact,
                'signature_valid' => (bool) $sigValid,
                'document_intact' => $intact,
                'signer_subject' => $signer,
                'detail' => ((bool) $sigValid && $intact) ? 'seal_valid' : (! $sigValid ? 'cms_signature_invalid' : 'document_modified_after_seal'),
            ];
        } finally {
            @unlink($sig);
            @unlink($extracted);
            @unlink($certsOut);
        }
    }
}
