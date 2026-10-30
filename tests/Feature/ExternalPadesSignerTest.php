<?php

namespace Tests\Feature;

use App\Core\Crypto\ExternalPadesSigner;
use App\Core\Crypto\NativePadesSignatureService;
use Tests\TestCase;

/**
 * W-SIGNATURE-6 (ADR-658) — signature PAdES EXTERNE (clé navigateur) : prototype prouvé.
 * Deux signatures personnelles distinctes embarquées, la clé privée ne touche jamais le serveur,
 * validation indépendante par pdfsig, préservation de la 1re signature, détection d'altération.
 */
class ExternalPadesSignerTest extends TestCase
{
    private function pdfsigAvailable(): bool
    {
        return app(NativePadesSignatureService::class)->pdfsigAvailable();
    }

    /** Identité signataire : clé EC P-256 (reste "côté navigateur") + cert auto-signé lié à cette clé. */
    private function ecSigner(string $cn): array
    {
        $pkey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn, 'countryName' => 'FR'], $pkey, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256']), $cert);

        return ['cert' => $cert, 'pkey' => $pkey];
    }

    /** Simule WebCrypto : signe `to_sign` (ECDSA SHA-256) → P1363 base64. La clé privée ne quitte pas "le navigateur". */
    private function browserSign(string $toSign, $priv): string
    {
        openssl_sign($toSign, $der, $priv, OPENSSL_ALGO_SHA256);
        $off = 2;
        $rLen = ord($der[$off + 1]);
        $r = substr($der, $off + 2, $rLen);
        $sStart = $off + 2 + $rLen;
        $sLen = ord($der[$sStart + 1]);
        $s = substr($der, $sStart + 2, $sLen);

        return base64_encode(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT));
    }

    private function signExternally(ExternalPadesSigner $svc, string $pdf, array $signer): string
    {
        $placeholder = $svc->preparePlaceholderPdf($pdf);
        $prepared = $svc->prepareDataToSign($placeholder, $signer['cert']);
        $p1363 = $this->browserSign(base64_decode($prepared['to_sign_b64']), $signer['pkey']);

        return $svc->injectSignature($placeholder, $prepared, $signer['cert'], $p1363);
    }

    public function test_single_external_signature_valid(): void
    {
        if (! $this->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $svc = new ExternalPadesSigner();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat</h1>')->output();
        $signed = $this->signExternally($svc, $pdf, $this->ecSigner('Alex Collaborateur'));

        $rep = app(NativePadesSignatureService::class)->verifyPdf($signed);
        $this->assertTrue($rep['all_signatures_valid'], 'signature externe valide (pdfsig)');
        $this->assertSame('Alex Collaborateur', $rep['signatures'][0]['signer_cn'] ?? null);
    }

    public function test_two_external_personal_signatures_both_valid(): void
    {
        if (! $this->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $svc = new ExternalPadesSigner();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat deux signataires</h1>')->output();

        $signedA = $this->signExternally($svc, $pdf, $this->ecSigner('Alex Collaborateur'));
        $signedAB = $this->signExternally($svc, $signedA, $this->ecSigner('Rep Leezr'));

        $rep = app(NativePadesSignatureService::class)->verifyPdf($signedAB);
        $this->assertSame(2, $rep['signature_count'], 'deux signatures embarquées');
        $this->assertTrue($rep['all_signatures_valid'], 'les DEUX valides (A préservée après B)');
        $cns = array_map(fn ($s) => $s['signer_cn'] ?? '', $rep['signatures']);
        $this->assertContains('Alex Collaborateur', $cns);
        $this->assertContains('Rep Leezr', $cns);
    }

    public function test_malformed_pdf_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ExternalPadesSigner())->preparePlaceholderPdf('this is not a pdf at all, just junk bytes here .....');
    }

    public function test_certificate_substitution_rejected(): void
    {
        if (! $this->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $svc = new ExternalPadesSigner();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Doc</h1>')->output();
        $alex = $this->ecSigner('Alex');
        $placeholder = $svc->preparePlaceholderPdf($pdf);
        $prepared = $svc->prepareDataToSign($placeholder, $alex['cert']);
        $p1363 = $this->browserSign(base64_decode($prepared['to_sign_b64']), $alex['pkey']);

        // Injection avec un AUTRE certificat que celui préparé → rejet (liaison préparation↔cert).
        $other = $this->ecSigner('Intrus');
        $this->expectException(\RuntimeException::class);
        $svc->injectSignature($placeholder, $prepared, $other['cert'], $p1363);
    }

    public function test_signature_not_matching_certificate_rejected(): void
    {
        if (! $this->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $svc = new ExternalPadesSigner();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Doc</h1>')->output();
        $alex = $this->ecSigner('Alex');
        $placeholder = $svc->preparePlaceholderPdf($pdf);
        $prepared = $svc->prepareDataToSign($placeholder, $alex['cert']);

        // Signature calculée avec la clé d'un AUTRE signataire → ne vérifie pas contre le cert d'Alex.
        $intrus = $this->ecSigner('Intrus');
        $badSig = $this->browserSign(base64_decode($prepared['to_sign_b64']), $intrus['pkey']);
        $this->expectException(\RuntimeException::class);
        $svc->injectSignature($placeholder, $prepared, $alex['cert'], $badSig);
    }

    public function test_tampering_detected(): void
    {
        if (! $this->pdfsigAvailable()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $svc = new ExternalPadesSigner();
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Contrat</h1>')->output();
        $signedAB = $this->signExternally($svc, $this->signExternally($svc, $pdf, $this->ecSigner('A')), $this->ecSigner('B'));

        $t = $signedAB;
        $t[300] = $t[300] === 'A' ? 'B' : 'A';
        $rep = app(NativePadesSignatureService::class)->verifyPdf($t);
        $this->assertFalse($rep['all_signatures_valid'], 'altération détectée');
    }
}
