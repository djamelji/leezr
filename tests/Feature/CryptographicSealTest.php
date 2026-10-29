<?php

namespace Tests\Feature;

use App\Core\Crypto\CryptographicSealService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W-SIGNATURE-2 (ADR-654) — cœur cryptographique : signature CMS/PKCS#7 détachée réelle,
 * vérification indépendante, détection d'altération, clé privée chiffrée au repos.
 */
class CryptographicSealTest extends TestCase
{
    private function service(): CryptographicSealService
    {
        Storage::fake('local');

        return new CryptographicSealService();
    }

    public function test_seal_then_verify_is_cryptographically_valid(): void
    {
        $svc = $this->service();
        $bytes = random_bytes(4096);
        $seal = $svc->seal($bytes);

        $this->assertNotEmpty($seal['cms_b64']);
        $this->assertStringContainsString('SHA-256', $seal['algorithm']);

        $res = $svc->verify($bytes, $seal['cms_b64']);
        $this->assertTrue($res['valid'], 'signature CMS cryptographiquement valide');
        $this->assertTrue($res['document_intact']);
    }

    public function test_tampered_content_fails_verification(): void
    {
        $svc = $this->service();
        $bytes = 'Document original exact.';
        $seal = $svc->seal($bytes);

        $res = $svc->verify('Document MODIFIE apres cachet.', $seal['cms_b64']);
        $this->assertFalse($res['valid'], 'toute altération du contenu invalide le cachet');
        $this->assertFalse($res['document_intact'], 'altération détectée');
    }

    public function test_private_key_is_encrypted_at_rest(): void
    {
        $svc = $this->service();
        $svc->seal('x'); // déclenche la génération

        $stored = Storage::disk('local')->get('crypto/leezr_seal.key.enc');
        // La clé privée NE DOIT PAS apparaître en clair.
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertStringNotContainsString('BEGIN RSA', $stored);
        // Le certificat public, lui, est stocké en clair (non secret).
        $this->assertStringContainsString('BEGIN CERTIFICATE', Storage::disk('local')->get('crypto/leezr_seal.crt'));
    }

    public function test_certificate_is_self_signed_dev_seal(): void
    {
        $svc = $this->service();
        $info = $svc->certificateInfo();
        $this->assertTrue($info['self_signed'], 'cachet auto-signé (pas de chaîne de confiance reconnue)');
        $this->assertSame('Leezr', $info['subject']['O'] ?? null);
        $this->assertNotEmpty($info['sha256_fingerprint']);
    }
}
