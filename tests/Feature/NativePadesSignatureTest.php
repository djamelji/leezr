<?php

namespace Tests\Feature;

use App\Core\Crypto\CryptographicSealService;
use App\Core\Crypto\NativePadesSignatureService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W-SIGNATURE-3 (ADR-655) — moteur PAdES natif : signature EMBARQUÉE réelle, vérifiée
 * INDÉPENDAMMENT par pdfsig, détection d'altération, modèle de confiance honnête.
 */
class NativePadesSignatureTest extends TestCase
{
    private function service(): NativePadesSignatureService
    {
        Storage::fake('local');

        return new NativePadesSignatureService(new CryptographicSealService());
    }

    private function realPdf(): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat de test</h1><p>Document réel dompdf.</p>')->output();
    }

    public function test_engine_and_pdfsig_available(): void
    {
        $svc = $this->service();
        $this->assertTrue($svc->isAvailable(), 'FPDI/TCPDF disponibles');
        if (! $svc->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent de cet environnement');
        }
        $this->assertTrue(true);
    }

    public function test_signs_pdf_with_embedded_signature_verified_independently(): void
    {
        $svc = $this->service();
        if (! $svc->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $signed = $svc->signPdf($this->realPdf());

        $this->assertStringStartsWith('%PDF', $signed);
        // Signature embarquée DANS le PDF (pas seulement en métadonnées).
        $this->assertTrue(str_contains($signed, '/SubFilter') || str_contains($signed, '/Type /Sig'));

        $report = $svc->verifyPdf($signed);
        $this->assertTrue($report['has_embedded_signature'], 'pdfsig voit une signature embarquée');
        $this->assertSame(1, $report['signature_count']);
        $this->assertTrue($report['all_signatures_valid'], 'pdfsig : signature valide');
        // Honnête : auto-signé → chaîne de confiance NON reconnue.
        $this->assertFalse($report['any_trusted_chain'], 'certificat auto-signé non reconnu');
        $this->assertStringContainsStringIgnoringCase('pkcs7', $report['signatures'][0]['type'] ?? '');
    }

    public function test_tampered_pdf_fails_independent_verification(): void
    {
        $svc = $this->service();
        if (! $svc->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $signed = $svc->signPdf($this->realPdf());

        // Altère un octet dans la zone signée.
        $tampered = $signed;
        $tampered[100] = $tampered[100] === 'A' ? 'B' : 'A';

        $report = $svc->verifyPdf($tampered);
        $this->assertFalse($report['all_signatures_valid'], 'altération détectée par pdfsig');
    }
}
