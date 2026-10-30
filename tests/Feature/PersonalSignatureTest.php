<?php

namespace Tests\Feature;

use App\Core\Crypto\PersonalSignatureService;
use Tests\TestCase;

/**
 * W-SIGNATURE-4 (ADR-656) — vérification de signature PERSONNELLE (ECDSA P-256, format WebCrypto
 * P1363). Prouve : signature valide vérifiée, altération rejetée, mauvaise clé rejetée. La clé privée
 * ne quitte jamais l'appareil (ici : on ne teste que la vérification côté serveur de la clé PUBLIQUE).
 */
class PersonalSignatureTest extends TestCase
{
    private PersonalSignatureService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PersonalSignatureService();
    }

    /** Génère une paire EC P-256 ; retourne [spkiDerBase64, privateResource]. */
    private function keypair(): array
    {
        $pkey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($pkey);
        $pubPem = $details['key'];
        // PEM public → DER base64 (comme un export SPKI WebCrypto).
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pubPem));

        return [base64_encode($der), $pkey];
    }

    /** Signe en openssl (DER) puis convertit en P1363 (format WebCrypto) base64. */
    private function signP1363(string $payload, $privateKey): string
    {
        openssl_sign($payload, $derSig, $privateKey, OPENSSL_ALGO_SHA256);

        // DER ECDSA-Sig-Value → r‖s (32+32).
        $offset = 2; // SEQ tag + len (len < 128 pour P-256)
        if (ord($derSig[1]) & 0x80) {
            $offset = 2 + (ord($derSig[1]) & 0x7F);
        }
        // INTEGER r
        $this->assertSame("\x02", $derSig[$offset]);
        $rLen = ord($derSig[$offset + 1]);
        $r = substr($derSig, $offset + 2, $rLen);
        $sStart = $offset + 2 + $rLen;
        $this->assertSame("\x02", $derSig[$sStart]);
        $sLen = ord($derSig[$sStart + 1]);
        $s = substr($derSig, $sStart + 2, $sLen);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return base64_encode($r . $s);
    }

    private function payload(): string
    {
        return $this->svc->canonicalPayload([
            'company_id' => 1, 'request_id' => 42, 'signer_id' => 7,
            'signer_email' => 'alex@example.test', 'document_sha256' => str_repeat('a', 64),
            'challenge' => 'nonce-xyz',
        ]);
    }

    public function test_valid_personal_signature_verifies(): void
    {
        [$spki, $priv] = $this->keypair();
        $payload = $this->payload();
        $sig = $this->signP1363($payload, $priv);

        $this->assertTrue($this->svc->verify($spki, $payload, $sig), 'signature personnelle valide vérifiée');
    }

    public function test_tampered_payload_fails(): void
    {
        [$spki, $priv] = $this->keypair();
        $payload = $this->payload();
        $sig = $this->signP1363($payload, $priv);

        $tampered = str_replace('nonce-xyz', 'nonce-HACK', $payload);
        $this->assertFalse($this->svc->verify($spki, $tampered, $sig), 'payload modifié → invalide');
    }

    public function test_wrong_key_fails(): void
    {
        [, $priv] = $this->keypair();
        [$otherSpki] = $this->keypair();
        $payload = $this->payload();
        $sig = $this->signP1363($payload, $priv);

        $this->assertFalse($this->svc->verify($otherSpki, $payload, $sig), 'clé publique différente → invalide');
    }

    public function test_malformed_signature_rejected(): void
    {
        [$spki] = $this->keypair();
        $this->assertFalse($this->svc->verify($spki, $this->payload(), base64_encode('too-short')));
    }

    public function test_key_id_is_stable_fingerprint(): void
    {
        [$spki] = $this->keypair();
        $this->assertSame($this->svc->keyId($spki), $this->svc->keyId($spki));
        $this->assertSame(64, strlen($this->svc->keyId($spki)));
    }
}
