<?php

namespace App\Core\Crypto;

use ddn\sapp\helpers\asn1;
use ddn\sapp\helpers\x509;
use ddn\sapp\PDFDoc;

/**
 * W-SIGNATURE-6/7 (ADR-658/659) — signature PAdES EXTERNE : le navigateur signe, le serveur assemble.
 *
 * Protocole : SAPP produit la structure PDF (révision incrémentale + champ de signature + ByteRange
 * + placeholder /Contents). On construit les ATTRIBUTS SIGNÉS CMS côté serveur, on calcule les octets
 * EXACTS à signer (`to_sign` = DER du SET d'attributs signés), le NAVIGATEUR les signe avec sa clé
 * privée WebCrypto (ECDSA P-256/SHA-256, NON extractible), puis le serveur assemble le CMS (signature
 * externe + certificat du signataire) et l'injecte dans le placeholder — SANS altérer les octets
 * couverts (hors ByteRange). La **clé privée n'est JAMAIS transmise ni reconstituée côté serveur.**
 *
 * DURCISSEMENT W-SIGNATURE-7 (§2) :
 *  - `locateSignature` valide STRUCTURELLEMENT le ByteRange (invariants de couverture : commence à 0,
 *    encadre exactement le gap /Contents, couvre tout le reste du fichier). Un PDF au ByteRange ambigu,
 *    incohérent ou malformé est REJETÉ plutôt que signé incorrectement.
 *  - La préparation est liée au placeholder (sha256) et au certificat (sha256). `injectSignature`
 *    vérifie que le placeholder et le certificat fournis correspondent EXACTEMENT à la préparation.
 *  - `injectSignature` VÉRIFIE CRYPTOGRAPHIQUEMENT la signature externe contre la clé publique du
 *    certificat (openssl) AVANT injection → anti-substitution de certificat, anti-injection de données
 *    arbitraires. Les octets hors gap ne sont jamais modifiés.
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
        $this->assertLooksLikePdf($pdfBytes);
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
        // Valide immédiatement la structure produite (fail-fast si SAPP a généré un ByteRange inattendu).
        $this->locateSignature($out);

        return $out;
    }

    /**
     * Phase 1 — à partir du PDF placeholder : calcule les octets exacts à signer par le navigateur.
     *
     * @return array{to_sign_b64:string, auth_attrs_hex:string, byte_range:array, contents_hex_start:int,
     *               contents_hex_len:int, placeholder_sha256:string, signer_cert_sha256:string}
     */
    public function prepareDataToSign(string $placeholderPdf, string $signerCertPem): array
    {
        $this->assertValidCertificate($signerCertPem);
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
            // Liaisons anti-rejeu / anti-substitution (stockées par la couche métier avec request/signer/révision).
            'placeholder_sha256' => hash('sha256', $placeholderPdf),
            'signer_cert_sha256' => hash('sha256', $this->certDer($signerCertPem)),
        ];
    }

    /**
     * Phase 2 — assemble le CMS (attributs signés + signature ECDSA externe + cert) et l'injecte.
     *
     * Vérifie que le placeholder et le certificat fournis correspondent EXACTEMENT à la préparation,
     * puis vérifie cryptographiquement la signature externe contre la clé publique du certificat
     * AVANT toute injection. N'altère QUE les octets du gap /Contents (hors ByteRange).
     */
    public function injectSignature(string $placeholderPdf, array $prepared, string $signerCertPem, string $ecdsaP1363Base64): string
    {
        // (a) Liaison préparation ↔ placeholder ↔ certificat (empêche de réutiliser une signature sur
        //     d'autres octets / un autre document / un autre certificat).
        if (isset($prepared['placeholder_sha256']) && ! hash_equals($prepared['placeholder_sha256'], hash('sha256', $placeholderPdf))) {
            throw new \RuntimeException('Placeholder PDF does not match the prepared signing session.');
        }
        if (isset($prepared['signer_cert_sha256']) && ! hash_equals($prepared['signer_cert_sha256'], hash('sha256', $this->certDer($signerCertPem)))) {
            throw new \RuntimeException('Certificate does not match the prepared signing session.');
        }

        // (b) Re-localise structurellement et vérifie la cohérence avec la préparation.
        [$byteRange, $hexStart, $hexLen] = $this->locateSignature($placeholderPdf);
        if ($hexStart !== (int) $prepared['contents_hex_start'] || $hexLen !== (int) $prepared['contents_hex_len']
            || $byteRange !== array_map('intval', $prepared['byte_range'])) {
            throw new \RuntimeException('Prepared ByteRange no longer matches the placeholder structure.');
        }
        // NB : le gap contient le CMS jetable produit par SAPP ; on l'écrase intégralement. C'est sûr car
        // il est HORS ByteRange (garanti par locateSignature) — le contrôle (e) le prouve a posteriori.

        // (c) Décode la signature externe et VÉRIFIE-la cryptographiquement contre la clé publique du cert.
        $p1363 = base64_decode($ecdsaP1363Base64, true);
        if ($p1363 === false || strlen($p1363) !== 64) {
            throw new \RuntimeException('Invalid ECDSA P1363 signature.');
        }
        $sigDer = app(PersonalSignatureService::class)->p1363ToDerPublic($p1363);
        $toSign = hex2bin(asn1::set($prepared['auth_attrs_hex']));
        $pub = openssl_pkey_get_public($signerCertPem);
        if ($pub === false) {
            throw new \RuntimeException('Signer certificate public key unreadable.');
        }
        if (openssl_verify($toSign, $sigDer, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('External signature does not verify against the certificate public key.');
        }

        // (d) Assemble et injecte — uniquement dans le gap, octets couverts intacts.
        $cmsHex = $this->assembleCms($prepared['auth_attrs_hex'], $sigDer, $signerCertPem);
        if (strlen($cmsHex) > $hexLen) {
            throw new \RuntimeException('Assembled CMS exceeds reserved placeholder length.');
        }
        $cmsHexPadded = str_pad($cmsHex, $hexLen, '0');

        $signed = substr($placeholderPdf, 0, $hexStart) . $cmsHexPadded . substr($placeholderPdf, $hexStart + $hexLen);

        // (e) Garantie finale : les octets COUVERTS par le ByteRange sont bit-à-bit identiques.
        if ($this->byteRangeContent($signed, $byteRange) !== $this->byteRangeContent($placeholderPdf, $byteRange)) {
            throw new \RuntimeException('Covered bytes changed during injection — aborting.');
        }

        return $signed;
    }

    // ── internes ──

    /**
     * Localise ET valide structurellement le DERNIER champ de signature (placeholder le plus récent).
     *
     * Invariants exigés (sinon REJET) — c'est la garantie de sécurité, pas la simple recherche texte :
     *  - byteRange = [0, b, c, d] : commence à l'offset 0 ;
     *  - le '<' du gap /Contents est exactement à l'offset b, le '>' exactement à c-1 ;
     *  - la couverture est totale et sans trou : b + (gap) = c et c + d = taille du fichier ;
     *  - le gap est non vide et contient uniquement des chiffres hexadécimaux.
     *
     * @return array{0: array{0:int,1:int,2:int,3:int}, 1:int, 2:int}
     */
    private function locateSignature(string $pdf): array
    {
        if (! preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $all, PREG_SET_ORDER)) {
            throw new \RuntimeException('ByteRange not found.');
        }
        $m = end($all); // placeholder le plus récent (dernière révision incrémentale)
        $byteRange = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        $total = strlen($pdf);

        // Invariant 1 : commence à 0.
        if ($byteRange[0] !== 0) {
            throw new \RuntimeException('Rejected: ByteRange does not start at offset 0.');
        }
        // Invariant 2 : couverture totale (pré/post segment + gap = fichier entier).
        if ($byteRange[2] + $byteRange[3] !== $total) {
            throw new \RuntimeException('Rejected: ByteRange does not cover the whole document ("Not total document").');
        }
        // Invariant 3 : le gap /Contents est encadré exactement par le ByteRange.
        $ltPos = $byteRange[0] + $byteRange[1]; // doit pointer sur '<'
        $gtPos = $byteRange[2] - 1;             // doit pointer sur '>'
        if (($pdf[$ltPos] ?? '') !== '<' || ($pdf[$gtPos] ?? '') !== '>') {
            throw new \RuntimeException('Rejected: /Contents gap not framed by the ByteRange.');
        }
        $hexStart = $ltPos + 1;
        $hexLen = $gtPos - $hexStart;
        if ($hexLen <= 0) {
            throw new \RuntimeException('Rejected: empty /Contents gap.');
        }
        // Invariant 4 : le gap ne contient QUE de l'hexadécimal (pas de donnée arbitraire).
        if (! ctype_xdigit(substr($pdf, $hexStart, $hexLen))) {
            throw new \RuntimeException('Rejected: /Contents gap contains non-hex bytes.');
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
        $certHash = hash('sha256', $this->certDer($signerCertPem));

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
        $embedCert = bin2hex($this->certDer($signerCertPem));

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

    /** DER d'un certificat PEM (via SAPP, warnings EC neutralisés). */
    private function certDer(string $signerCertPem): string
    {
        $der = $this->withSuppressedWarnings(fn () => x509::get_cert($signerCertPem));
        if (! is_string($der) || $der === '') {
            throw new \RuntimeException('Unable to read signer certificate DER.');
        }

        return $der;
    }

    private function assertLooksLikePdf(string $bytes): void
    {
        if (strlen($bytes) < 64 || ! str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('Input is not a PDF document.');
        }
    }

    private function assertValidCertificate(string $pem): void
    {
        if (openssl_x509_read($pem) === false || openssl_pkey_get_public($pem) === false) {
            throw new \RuntimeException('Invalid signer certificate.');
        }
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
