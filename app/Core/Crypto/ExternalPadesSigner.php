<?php

namespace App\Core\Crypto;

use ddn\sapp\helpers\asn1;
use ddn\sapp\helpers\x509;
use ddn\sapp\PDFDoc;

/**
 * W-SIGNATURE-6 (ADR-658) — signature PAdES EXTERNE : le navigateur signe, le serveur assemble.
 *
 * Protocole : SAPP produit la structure PDF (révision incrémentale + champ de signature + ByteRange
 * + placeholder /Contents). On construit les ATTRIBUTS SIGNÉS CMS côté serveur, on calcule les octets
 * EXACTS à signer (`to_sign` = DER du SET d'attributs signés), le NAVIGATEUR les signe avec sa clé
 * privée WebCrypto (ECDSA P-256/SHA-256, NON extractible), puis le serveur assemble le CMS (signature
 * externe + certificat du signataire) et l'injecte dans le placeholder — SANS altérer les octets
 * couverts (hors ByteRange). La **clé privée n'est JAMAIS transmise ni reconstituée côté serveur.**
 *
 * Mirror fidèle de la construction CMS de SAPP, adapté ECDSA (OID ecdsa-with-SHA256, signature = DER
 * ECDSA-Sig-Value, pas de chiffrement RSA). Résultat vérifiable indépendamment par pdfsig.
 */
class ExternalPadesSigner
{
    private const SHA256_OID = '0609608648016503040201';
    private const ECDSA_SHA256_OID = '06082A8648CE3D040302'; // 1.2.840.10045.4.3.2 (no params)

    /**
     * Produit un PDF avec un placeholder de signature (révision incrémentale via SAPP), en signant
     * avec un certificat/clé JETABLE : seuls la STRUCTURE et le ByteRange nous intéressent ; le CMS
     * jetable sera intégralement remplacé par le CMS externe du signataire.
     */
    public function preparePlaceholderPdf(string $pdfBytes): string
    {
        $throwaway = $this->throwawayCert();
        $doc = PDFDoc::from_string($pdfBytes);
        if (! $doc) {
            throw new \RuntimeException('SAPP could not parse the PDF.');
        }
        $doc->set_signature_certificate(['cert' => $throwaway['cert'], 'pkey' => $throwaway['pkey'], 'extracerts' => null]);
        $out = $doc->to_pdf_file_s(false);
        if (! is_string($out) || ! str_starts_with($out, '%PDF')) {
            throw new \RuntimeException('SAPP placeholder generation failed.');
        }

        return $out;
    }

    /**
     * Phase 1 — à partir du PDF placeholder : calcule les octets exacts à signer par le navigateur.
     * @return array{to_sign_b64:string, auth_attrs_hex:string, byte_range:array, contents_hex_start:int, contents_hex_len:int}
     */
    public function prepareDataToSign(string $placeholderPdf, string $signerCertPem): array
    {
        [$byteRange, $contentsHexStart, $contentsHexLen] = $this->locateSignature($placeholderPdf);
        $content = $this->byteRangeContent($placeholderPdf, $byteRange);

        $authAttrs = $this->buildSignedAttributes($content, $signerCertPem);
        $toSign = hex2bin(asn1::set($authAttrs)); // le navigateur signe ces octets (SHA-256+ECDSA)

        return [
            'to_sign_b64' => base64_encode($toSign),
            'auth_attrs_hex' => $authAttrs,
            'byte_range' => $byteRange,
            'contents_hex_start' => $contentsHexStart,
            'contents_hex_len' => $contentsHexLen,
        ];
    }

    /**
     * Phase 2 — assemble le CMS (attributs signés + signature ECDSA externe + cert) et l'injecte.
     */
    public function injectSignature(string $placeholderPdf, array $prepared, string $signerCertPem, string $ecdsaP1363Base64): string
    {
        $p1363 = base64_decode($ecdsaP1363Base64, true);
        if ($p1363 === false || strlen($p1363) !== 64) {
            throw new \RuntimeException('Invalid ECDSA P1363 signature.');
        }
        $sigDer = app(PersonalSignatureService::class)->p1363ToDerPublic($p1363);

        $cmsHex = $this->assembleCms($prepared['auth_attrs_hex'], $sigDer, $signerCertPem);
        $len = $prepared['contents_hex_len'];
        if (strlen($cmsHex) > $len) {
            throw new \RuntimeException('Assembled CMS exceeds reserved placeholder length.');
        }
        $cmsHexPadded = str_pad($cmsHex, $len, '0');

        // Injection byte-exacte dans le gap /Contents (hors ByteRange → signatures intactes).
        return substr($placeholderPdf, 0, $prepared['contents_hex_start'])
            . $cmsHexPadded
            . substr($placeholderPdf, $prepared['contents_hex_start'] + $len);
    }

    // ── internes ──

    /**
     * Localise le DERNIER /ByteRange + le gap /Contents <...> (le placeholder le PLUS RÉCENT).
     * Essentiel pour l'incrémental : on signe le nouveau champ, jamais une signature antérieure.
     */
    private function locateSignature(string $pdf): array
    {
        if (! preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $all, PREG_SET_ORDER)) {
            throw new \RuntimeException('ByteRange not found.');
        }
        $m = end($all); // le plus récent (dernière révision incrémentale)
        $byteRange = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];

        // Le '<' de /Contents est à l'offset a+b (= byteRange[1]) ; le '>' juste avant byteRange[2].
        $ltPos = $byteRange[1];
        if (($pdf[$ltPos] ?? '') !== '<') {
            throw new \RuntimeException('Unexpected /Contents position (expected "<" at ByteRange offset).');
        }
        $gtPos = $byteRange[2] - 1;
        $hexStart = $ltPos + 1;
        $hexLen = $gtPos - $hexStart;
        if ($hexLen <= 0) {
            throw new \RuntimeException('Invalid /Contents gap.');
        }

        return [$byteRange, $hexStart, $hexLen];
    }

    private function byteRangeContent(string $pdf, array $br): string
    {
        return substr($pdf, $br[0], $br[1]) . substr($pdf, $br[2], $br[3]);
    }

    private function buildSignedAttributes(string $content, string $signerCertPem): string
    {
        
        $messageDigest = hash('sha256', $content);
        $certDer = $this->withSuppressedWarnings(fn () => x509::get_cert($signerCertPem));
        $certHash = hash('sha256', $certDer);

        $sha256AlgId = asn1::seq(self::SHA256_OID . '0500');
        $essCertIDv2 = asn1::seq($sha256AlgId . asn1::oct($certHash));
        $signingCertV2Attr = asn1::seq(
            '060B2A864886F70D010910022F' .
            asn1::set(asn1::seq(asn1::seq($essCertIDv2)))
        );

        return asn1::seq(
            '06092A864886F70D010903' . asn1::set('06092A864886F70D010701') // contentType = data
        ) . asn1::seq(
            '06092A864886F70D010905' . asn1::set(asn1::utime(time()))      // signingTime
        ) . asn1::seq(
            '06092A864886F70D010904' . asn1::set(asn1::oct($messageDigest)) // messageDigest
        ) . $signingCertV2Attr;
    }

    private function assembleCms(string $authAttrsHex, string $sigDer, string $signerCertPem): string
    {
        // Le parseur x509 de SAPP émet des warnings sur les certificats EC (bug connu) ; la signature
        // reste valide (pdfsig résout le certificat via signingCertV2/certHash). On neutralise ces
        // warnings non fatals sans masquer d'erreur réelle.
        $certParse = $this->withSuppressedWarnings(fn () => x509::readcert($signerCertPem));
        $issuerName = $certParse['tbsCertificate']['issuer']['hexdump'] ?? '';
        $serialNumber = $certParse['tbsCertificate']['serialNumber'] ?? '01';
        $embedCert = bin2hex($this->withSuppressedWarnings(fn () => x509::get_cert($signerCertPem)));

        $signerInfo = asn1::seq(
            asn1::int('1') .
            asn1::seq($issuerName . asn1::int($serialNumber)) .
            asn1::seq(self::SHA256_OID . '0500') .              // digestAlgorithm
            asn1::expl(0, $authAttrsHex) .                      // signedAttrs [0]
            asn1::seq(self::ECDSA_SHA256_OID) .                 // signatureAlgorithm = ecdsa-with-SHA256
            asn1::oct(bin2hex($sigDer))                         // signature = DER ECDSA-Sig-Value
        );

        $signedData = asn1::seq(
            asn1::int('1') .
            asn1::set(asn1::seq(self::SHA256_OID . '0500')) .   // digestAlgorithms
            asn1::seq('06092A864886F70D010701') .                // encapContentInfo = data (detached)
            asn1::expl(0, $embedCert) .                          // certificates [0]
            asn1::set($signerInfo)                               // signerInfos
        );

        return asn1::seq('06092A864886F70D010702' . asn1::expl(0, $signedData)); // ContentInfo signedData
    }

    /** Exécute $fn en neutralisant les warnings/notices/deprecated non fatals de SAPP (bug EC). */
    private function withSuppressedWarnings(callable $fn)
    {
        set_error_handler(fn () => true, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    private function throwawayCert(): array
    {
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'placeholder'], $pkey, ['digest_alg' => 'sha256']);
        $x = openssl_csr_sign($csr, null, $pkey, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($x, $cert);
        openssl_pkey_export($pkey, $key);

        return ['cert' => $cert, 'pkey' => $key];
    }
}
