<?php

namespace App\Core\Crypto;

use ddn\sapp\PDFDoc;

/**
 * W-SIGNATURE-5 (ADR-657) — moteur multi-signature PAdES INCRÉMENTALE (open source `ddn/sapp`, LGPL).
 *
 * Ajoute une signature PKCS#7/PAdES à un PDF par **mise à jour incrémentale** : une nouvelle signature
 * est ajoutée sans réécrire les octets couverts par les signatures PRÉCÉDENTES → toutes restent valides.
 * Vérification INDÉPENDANTE par `pdfsig` (poppler). Prototype prouvé : 2 certificats distincts, 2
 * signatures valides, altération détectée.
 *
 * ⚠ CONTRÔLE DE CLÉ : SAPP signe avec une clé fournie AU SERVEUR. Pour une signature réellement
 * contrôlée par le signataire (clé privée WebCrypto non-extractible, jamais transmise), il faut un
 * protocole de SIGNATURE EXTERNE (le navigateur signe le condensé CMS, le serveur injecte) — NON
 * fourni par ce service. Ne JAMAIS présenter une signature faite avec une clé côté serveur comme une
 * signature personnelle contrôlée par le signataire.
 */
class MultiPadesSignatureService
{
    public function available(): bool
    {
        return class_exists(PDFDoc::class);
    }

    public function pdfsigAvailable(): bool
    {
        return trim((string) @shell_exec('command -v pdfsig 2>/dev/null')) !== '';
    }

    /**
     * Ajoute une signature PAdES incrémentale au PDF, avec un certificat + clé donnés (côté serveur).
     * @param array{cert:string,pkey:string,extracerts?:?string} $certificate
     * @throws \RuntimeException
     */
    public function addIncrementalSignature(string $pdfBytes, array $certificate, array $info = []): string
    {
        if (! $this->available()) {
            throw new \RuntimeException('Multi-PAdES engine (SAPP) unavailable.');
        }
        $doc = PDFDoc::from_string($pdfBytes);
        if (! $doc) {
            throw new \RuntimeException('SAPP could not parse the PDF.');
        }
        $cert = ['cert' => $certificate['cert'], 'pkey' => $certificate['pkey'], 'extracerts' => $certificate['extracerts'] ?? null];
        if ($doc->set_signature_certificate($cert) !== true) {
            throw new \RuntimeException('SAPP rejected the signing certificate.');
        }
        $signed = $doc->to_pdf_file_s(false); // false = incrémental (préserve les signatures antérieures)
        if (! is_string($signed) || ! str_starts_with($signed, '%PDF')) {
            throw new \RuntimeException('SAPP produced an invalid signed PDF.');
        }

        return $signed;
    }

    /**
     * Vérifie INDÉPENDAMMENT toutes les signatures embarquées via pdfsig (réutilise le parseur natif).
     * @return array rapport (signatures multiples, validité par signature, intégrité).
     */
    public function verify(string $pdfBytes): array
    {
        return app(NativePadesSignatureService::class)->verifyPdf($pdfBytes);
    }
}
