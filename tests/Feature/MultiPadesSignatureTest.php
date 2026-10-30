<?php

namespace Tests\Feature;

use App\Core\Crypto\MultiPadesSignatureService;
use Tests\TestCase;

/**
 * W-SIGNATURE-5 (ADR-657) — moteur multi-PAdES incrémental (SAPP) : 2 signatures distinctes,
 * préservation des signatures antérieures, détection d'altération, validation indépendante pdfsig.
 */
class MultiPadesSignatureTest extends TestCase
{
    private function svc(): MultiPadesSignatureService
    {
        return new MultiPadesSignatureService();
    }

    private function cert(string $cn): array
    {
        $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $cn, 'organizationName' => 'Leezr DEV', 'countryName' => 'FR'], $pkey, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($pkey, $keyPem);

        return ['cert' => $certPem, 'pkey' => $keyPem, 'extracerts' => null];
    }

    private function realPdf(): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat</h1><p>Deux signataires.</p>')->output();
    }

    public function test_two_distinct_signatures_both_valid_independently(): void
    {
        $svc = $this->svc();
        if (! $svc->available() || ! $svc->pdfsigAvailable()) {
            $this->markTestSkipped('SAPP/pdfsig indisponible');
        }

        $signedA = $svc->addIncrementalSignature($this->realPdf(), $this->cert('Collaborateur Alex'));
        $reportA = $svc->verify($signedA);
        $this->assertTrue($reportA['all_signatures_valid'], 'signature A valide');
        $this->assertSame(1, $reportA['signature_count']);

        // Signature B en INCRÉMENTAL sur le PDF déjà signé A.
        $signedAB = $svc->addIncrementalSignature($signedA, $this->cert('Representant Leezr'));
        $reportAB = $svc->verify($signedAB);

        // DEUX signatures distinctes, les DEUX valides (A préservée après B).
        $this->assertSame(2, $reportAB['signature_count'], 'deux signatures embarquées');
        $this->assertTrue($reportAB['all_signatures_valid'], 'A ET B valides');
        $cns = array_map(fn ($s) => $s['signer_cn'] ?? '', $reportAB['signatures']);
        $this->assertContains('Collaborateur Alex', $cns);
        $this->assertContains('Representant Leezr', $cns);
    }

    public function test_tampering_invalidates_signatures(): void
    {
        $svc = $this->svc();
        if (! $svc->available() || ! $svc->pdfsigAvailable()) {
            $this->markTestSkipped('SAPP/pdfsig indisponible');
        }
        $signedA = $svc->addIncrementalSignature($this->realPdf(), $this->cert('Collaborateur Alex'));
        $signedAB = $svc->addIncrementalSignature($signedA, $this->cert('Representant Leezr'));

        $tampered = $signedAB;
        $tampered[200] = $tampered[200] === 'A' ? 'B' : 'A';
        $report = $svc->verify($tampered);
        $this->assertFalse($report['all_signatures_valid'], 'altération détectée (digest mismatch)');
    }

    public function test_certificate_trust_chain_not_recognized_honest(): void
    {
        $svc = $this->svc();
        if (! $svc->available() || ! $svc->pdfsigAvailable()) {
            $this->markTestSkipped('SAPP/pdfsig indisponible');
        }
        $signed = $svc->addIncrementalSignature($this->realPdf(), $this->cert('Collaborateur Alex'));
        $report = $svc->verify($signed);
        // Honnêteté : certificats internes/auto-signés → chaîne de confiance NON reconnue.
        $this->assertFalse($report['any_trusted_chain'], 'certificat non reconnu par une AC');
    }
}
