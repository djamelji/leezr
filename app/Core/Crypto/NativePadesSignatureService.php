<?php

namespace App\Core\Crypto;

/**
 * W-SIGNATURE-3 (ADR-655) — moteur de signature PAdES NATIF, réutilisable (hors Workforce).
 *
 * Produit un PDF comportant une **signature cryptographique EMBARQUÉE** (PKCS#7 / adbe.pkcs7.detached)
 * via FPDI (import du PDF source) + TCPDF (setSignature, OpenSSL) — pas d'algorithme maison, pas de
 * prestataire commercial. Vérification INDÉPENDANTE par `pdfsig` (poppler-utils), outil tiers.
 *
 * MODÈLE DE CONFIANCE (honnête) : la clé est le **cachet organisationnel SERVEUR Leezr** (auto-signé,
 * DEV) — ce N'EST PAS une signature contrôlée par le signataire ni une signature avancée/qualifiée eIDAS.
 *
 * LIMITE : chaque signature re-emballe le PDF (FPDI) → **une seule signature embarquée** ; le
 * multi-signature PAdES incrémental n'est PAS fourni (voir ADR-655).
 */
class NativePadesSignatureService
{
    public function __construct(private readonly CryptographicSealService $seal) {}

    public function isAvailable(): bool
    {
        return class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class) && class_exists(\TCPDF::class);
    }

    public function pdfsigAvailable(): bool
    {
        return $this->pdfsigBinary() !== null;
    }

    /**
     * Résout le binaire `pdfsig` de façon robuste, indépendamment du PATH du process (le PATH de
     * php-fpm/Valet n'inclut pas toujours /opt/homebrew/bin ou /usr/local/bin). Ordre : config explicite
     * → PATH → emplacements connus (Homebrew macOS, Linux). Renvoie le chemin absolu, ou null si absent.
     */
    private function pdfsigBinary(): ?string
    {
        $configured = config('signature.pdfsig_path');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        $fromPath = trim((string) @shell_exec('command -v pdfsig 2>/dev/null'));
        if ($fromPath !== '' && is_executable($fromPath)) {
            return $fromPath;
        }
        foreach (['/opt/homebrew/bin/pdfsig', '/usr/local/bin/pdfsig', '/usr/bin/pdfsig'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Signe un PDF source : renvoie les octets du PDF avec signature embarquée.
     * @throws \RuntimeException si la signature échoue.
     */
    public function signPdf(string $sourceBytes, array $info = []): string
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('PAdES engine unavailable (FPDI/TCPDF missing).');
        }

        $src = tempnam(sys_get_temp_dir(), 'pades_src_');
        file_put_contents($src, $sourceBytes);

        try {
            return $this->seal->usingSigningMaterial(function (string $certPem, string $keyPem) use ($src, $info) {
                $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->setAutoPageBreak(false);
                $n = $pdf->setSourceFile($src);
                for ($i = 1; $i <= $n; $i++) {
                    $tpl = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
                $pdf->setSignature($certPem, $keyPem, '', '', 2, array_merge([
                    'Name' => 'Leezr Organizational Seal',
                    'Location' => 'FR',
                    'Reason' => 'Document final signé (cachet organisationnel Leezr)',
                ], $info));

                $out = $pdf->Output('', 'S');
                if (! is_string($out) || ! str_starts_with($out, '%PDF')) {
                    throw new \RuntimeException('PAdES output is not a valid PDF.');
                }
                if (! str_contains($out, '/Type /Sig') && ! str_contains($out, '/SubFilter')) {
                    throw new \RuntimeException('PAdES output has no embedded signature dictionary.');
                }

                return $out;
            });
        } finally {
            @unlink($src);
        }
    }

    /**
     * Vérifie INDÉPENDAMMENT les signatures embarquées d'un PDF via `pdfsig` (poppler).
     * @return array rapport structuré (signatures, validité, intégrité, confiance).
     */
    public function verifyPdf(string $pdfBytes): array
    {
        $bin = $this->pdfsigBinary();
        if ($bin === null) {
            return ['tool' => null, 'available' => false, 'detail' => 'pdfsig_unavailable'];
        }

        $file = tempnam(sys_get_temp_dir(), 'pades_ver_');
        file_put_contents($file, $pdfBytes);
        try {
            $output = (string) @shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($file) . ' 2>&1');

            return $this->parsePdfsig($output);
        } finally {
            @unlink($file);
        }
    }

    private function parsePdfsig(string $output): array
    {
        $signatures = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (preg_match('/^Signature #(\d+):/', $line, $m)) {
                if ($current) {
                    $signatures[] = $current;
                }
                $current = ['index' => (int) $m[1]];
            } elseif ($current !== null) {
                if (preg_match('/Signer Certificate Common Name:\s*(.+)/', $line, $m)) {
                    $current['signer_cn'] = trim($m[1]);
                } elseif (preg_match('/Signing Hash Algorithm:\s*(.+)/', $line, $m)) {
                    $current['hash_algorithm'] = trim($m[1]);
                } elseif (preg_match('/Signature Type:\s*(.+)/', $line, $m)) {
                    $current['type'] = trim($m[1]);
                } elseif (preg_match('/Signature Validation:\s*(.+)/', $line, $m)) {
                    $v = trim($m[1]);
                    $current['signature_validation'] = $v;
                    $current['signature_valid'] = stripos($v, 'is Valid') !== false;
                    $current['digest_mismatch'] = stripos($v, 'Digest Mismatch') !== false;
                } elseif (preg_match('/Certificate Validation:\s*(.+)/', $line, $m)) {
                    $c = trim($m[1]);
                    $current['certificate_validation'] = $c;
                    $current['certificate_trusted'] = stripos($c, 'Trusted') !== false && stripos($c, "isn't") === false && stripos($c, 'not') === false;
                } elseif (stripos($line, 'Total document signed') !== false) {
                    $current['total_document_signed'] = true;
                }
            }
        }
        if ($current) {
            $signatures[] = $current;
        }

        $hasSig = count($signatures) > 0;
        $allValid = $hasSig && ! array_filter($signatures, fn ($s) => empty($s['signature_valid']));

        return [
            'tool' => 'pdfsig',
            'available' => true,
            'has_embedded_signature' => $hasSig,
            'signature_count' => count($signatures),
            'signatures' => $signatures,
            'all_signatures_valid' => $allValid,
            // Confiance : auto-signé → non reconnu par une AC (honnête).
            'any_trusted_chain' => $hasSig && (bool) array_filter($signatures, fn ($s) => $s['certificate_trusted'] ?? false),
            'raw' => $output,
        ];
    }
}
