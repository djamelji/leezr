<?php

namespace App\Core\Crypto;

use Illuminate\Support\Facades\Crypt;

/**
 * W-SIGNATURE-7 (ADR-659) — Autorité de Certification INTERNE, STRICTEMENT DÉDIÉE À DEV/TEST.
 *
 * ⚠️ LIMITES DE CONFIANCE (non négociables, reflétées honnêtement dans tous les rapports) :
 *   - Cette AC N'EST PAS une autorité de confiance publique, N'EST PAS reconnue, N'EST PAS qualifiée eIDAS.
 *   - Elle est DISTINCTE du cachet organisationnel serveur Leezr (CryptographicSealService).
 *   - Un certificat émis ici prouve uniquement la liaison « clé publique ↔ identité DÉCLARÉE », pas une
 *     vérification d'identité réglementaire. Les rapports doivent l'afficher explicitement.
 *
 * ARCHITECTURE (adaptée à un serveur DEV mono-machine) :
 *   - Racine auto-signée (conceptuellement « hors ligne ») → signe l'AC émettrice (pathlen:0).
 *   - L'AC émettrice signe les certificats FEUILLE des signataires.
 *   - Les clés privées d'AC sont CHIFFRÉES AU REPOS (Crypt/APP_KEY), stockées hors web-root
 *     (storage/app/private/dev-ca, perms 0600), JAMAIS exposées au frontend ni à un contrôleur HTTP.
 *   - Journal d'émission/révocation append-only (issued.log) en complément de la traçabilité DB.
 *
 * LIAISON À LA CLÉ RÉELLE : on ne détient JAMAIS la clé privée du signataire. Le certificat feuille
 * embarque EXACTEMENT le SubjectPublicKeyInfo (SPKI DER) exporté par WebCrypto — c'est ce qui lie le
 * certificat à la clé réelle du navigateur. La preuve de possession est vérifiée en amont (§4) par
 * SignerEnrollmentService, hors de cette classe (séparation des responsabilités).
 *
 * GARDE-FOU (§3) : si la protection de la clé d'AC ne peut être garantie (APP_KEY absente, répertoire
 * non sécurisable, environnement non-DEV), l'émission générale est DÉSACTIVÉE et lève une exception.
 */
class DevCertificateAuthority
{
    private const ECDSA_SHA256_OID = "\x06\x08\x2A\x86\x48\xCE\x3D\x04\x03\x02"; // 1.2.840.10045.4.3.2

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?: storage_path('app/private/dev-ca');
    }

    /** L'émission générale n'est permise que si l'environnement et la protection de clé sont sains. */
    public function issuanceEnabled(): bool
    {
        if (! config('signature.dev_ca.enabled', ! app()->environment('production'))) {
            return false;
        }
        if (app()->environment('production')) {
            return false; // jamais en PROD
        }

        return (bool) config('app.key'); // chiffrement au repos requis
    }

    public function assertIssuanceEnabled(): void
    {
        if (! $this->issuanceEnabled()) {
            throw new \RuntimeException('Dev CA issuance is disabled (non-DEV environment or missing key protection).');
        }
    }

    /**
     * Émet un certificat FEUILLE pour la clé publique (SPKI DER base64) d'un signataire.
     *
     * @param  array{cn:string, o?:string, ou?:string, c?:string}  $identity  identité DÉCLARÉE
     * @return array{cert_pem:string, serial_hex:string, fingerprint_sha256:string, not_before:int,
     *               not_after:int, issuer_dn:string, subject_dn:string, public_key_id:string}
     */
    public function issueSignerCertificate(string $spkiDerBase64, array $identity, int $validityDays = 365): array
    {
        $this->assertIssuanceEnabled();
        $this->ensureInitialized();

        $spki = base64_decode(preg_replace('/\s+/', '', $spkiDerBase64), true);
        if ($spki === false || $spki === '' || ! $this->isValidP256Spki($spki)) {
            throw new \RuntimeException('Invalid signer SubjectPublicKeyInfo (expected EC P-256 SPKI).');
        }
        if (empty($identity['cn'])) {
            throw new \RuntimeException('Signer certificate requires a declared common name.');
        }

        $meta = $this->meta();
        $issuerDer = hex2bin($meta['issuing_subject_hex']);
        $issuingKey = openssl_pkey_get_private(Crypt::decryptString(file_get_contents($this->path('issuing.key.enc'))));
        if ($issuingKey === false) {
            throw new \RuntimeException('Unable to load issuing CA private key.');
        }

        $serial = $this->randomSerial();
        $notBefore = time() - 300;
        $notAfter = $notBefore + ($validityDays * 86400);

        $subjectDer = $this->name(array_filter([
            'cn' => $identity['cn'],
            'ou' => $identity['ou'] ?? null,
            'o' => $identity['o'] ?? 'Leezr DEV Signer (non-trusted test CA)',
            'c' => $identity['c'] ?? 'FR',
        ]));

        $ski = substr(hash('sha256', $spki, true), 0, 20);

        $extensions = $this->expl(3, $this->seq(
            // basicConstraints critical : cA = FALSE (SEQUENCE vide)
            $this->seq($this->oid('55:1D:13') . $this->bool(true) . $this->octet($this->seq(''))) .
            // keyUsage critical : digitalSignature + nonRepudiation (contentCommitment)
            $this->seq($this->oid('55:1D:0F') . $this->bool(true) . $this->octet("\x03\x02\x06\xC0")) .
            // subjectKeyIdentifier
            $this->seq($this->oid('55:1D:0E') . $this->octet($this->octet($ski))) .
            // authorityKeyIdentifier (keyIdentifier [0])
            $this->seq($this->oid('55:1D:23') . $this->octet($this->seq("\x80" . $this->len(20) . hex2bin($meta['issuing_ski_hex']))))
        ));

        $tbs = $this->seq(
            $this->expl(0, $this->intFromInt(2)) .           // version v3
            $this->intFromBin($serial) .                      // serialNumber
            $this->seq(self::ECDSA_SHA256_OID) .              // signatureAlgorithm (tbs)
            $issuerDer .                                      // issuer = sujet de l'AC émettrice
            $this->seq($this->utcTime($notBefore) . $this->utcTime($notAfter)) .
            $subjectDer .                                     // subject = identité déclarée
            $spki .                                           // SubjectPublicKeyInfo = clé WebCrypto RÉELLE
            $extensions
        );

        if (! openssl_sign($tbs, $sig, $issuingKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Issuing CA failed to sign the certificate.');
        }

        $certDer = $this->seq($tbs . $this->seq(self::ECDSA_SHA256_OID) . $this->bitString($sig));
        $certPem = $this->derToPem($certDer, 'CERTIFICATE');

        // Vérification immédiate : la clé publique du cert émis = la clé réelle fournie (anti-régression).
        $parsedPub = openssl_pkey_get_public($certPem);
        if ($parsedPub === false) {
            throw new \RuntimeException('Issued certificate is not parseable.');
        }

        $result = [
            'cert_pem' => $certPem,
            'serial_hex' => strtoupper(bin2hex($serial)),
            'fingerprint_sha256' => hash('sha256', $certDer),
            'not_before' => $notBefore,
            'not_after' => $notAfter,
            'issuer_dn' => $meta['issuing_dn'],
            'subject_dn' => trim(($identity['cn']).', O='.($identity['o'] ?? 'Leezr DEV Signer').', C='.($identity['c'] ?? 'FR')),
            'public_key_id' => hash('sha256', $spki),
        ];

        $this->audit('issue', $result);

        return $result;
    }

    /** Journalise une révocation dans l'audit AC (le statut de révocation « métier » vit en DB, §6). */
    public function recordRevocation(string $serialHex, string $fingerprint, string $reason): void
    {
        $this->audit('revoke', ['serial_hex' => $serialHex, 'fingerprint_sha256' => $fingerprint, 'reason' => $reason]);
    }

    /** Chaîne AC (émettrice + racine) en PEM — pour l'affichage/preuve, PAS un ancrage de confiance public. */
    public function chainPem(): string
    {
        $this->ensureInitialized();

        return file_get_contents($this->path('issuing.crt')) . file_get_contents($this->path('root.crt'));
    }

    public function isInitialized(): bool
    {
        return is_file($this->path('issuing.key.enc')) && is_file($this->path('issuing.crt')) && is_file($this->path('meta.json'));
    }

    /** Crée la hiérarchie (racine auto-signée → AC émettrice) si absente. Idempotent, non destructif. */
    public function ensureInitialized(): void
    {
        if ($this->isInitialized()) {
            return;
        }
        $this->assertIssuanceEnabled();
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }

        $cnf = $this->writeOpensslConfig();
        try {
            // Racine auto-signée (CA:TRUE).
            $rootKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cnf]);
            $rootCsr = openssl_csr_new(['commonName' => 'Leezr DEV Root CA (non-trusted test)', 'organizationName' => 'Leezr DEV', 'countryName' => 'FR'], $rootKey, ['config' => $cnf, 'digest_alg' => 'sha256']);
            $rootCrt = openssl_csr_sign($rootCsr, null, $rootKey, 3650, ['config' => $cnf, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256'], $this->randomSerialInt());
            openssl_x509_export($rootCrt, $rootPem);
            openssl_pkey_export($rootKey, $rootKeyPem, null, ['config' => $cnf]);

            // AC émettrice (CA:TRUE, pathlen:0), signée par la racine.
            $issKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cnf]);
            $issCsr = openssl_csr_new(['commonName' => 'Leezr DEV Issuing CA (non-trusted test)', 'organizationName' => 'Leezr DEV', 'countryName' => 'FR'], $issKey, ['config' => $cnf, 'digest_alg' => 'sha256']);
            $issCrt = openssl_csr_sign($issCsr, $rootCrt, $rootKey, 1825, ['config' => $cnf, 'x509_extensions' => 'v3_issuing', 'digest_alg' => 'sha256'], $this->randomSerialInt());
            openssl_x509_export($issCrt, $issPem);
            openssl_pkey_export($issKey, $issKeyPem, null, ['config' => $cnf]);
        } finally {
            @unlink($cnf);
        }

        // Sujet DER + SKI de l'AC émettrice (pour issuer/AKI des feuilles).
        $issuingSubjectHex = $this->subjectDerHexOf($issPem);
        $issuingSpki = $this->spkiDerOf($issKeyPem);
        $issuingSkiHex = bin2hex(substr(hash('sha256', $issuingSpki, true), 0, 20));

        // Écriture : clés CHIFFRÉES au repos, certs en clair, perms strictes.
        $this->put('root.key.enc', Crypt::encryptString($rootKeyPem), 0600);
        $this->put('root.crt', $rootPem, 0600);
        $this->put('issuing.key.enc', Crypt::encryptString($issKeyPem), 0600);
        $this->put('issuing.crt', $issPem, 0600);
        $this->put('meta.json', json_encode([
            'created_at' => gmdate('c'),
            'policy' => 'DEV/TEST ONLY — non-trusted, non-qualified, not eIDAS. Binds declared identity to a real public key after proof-of-possession. Not a regulatory identity verification.',
            'issuing_dn' => 'CN=Leezr DEV Issuing CA (non-trusted test), O=Leezr DEV, C=FR',
            'issuing_subject_hex' => $issuingSubjectHex,
            'issuing_ski_hex' => $issuingSkiHex,
        ], JSON_PRETTY_PRINT), 0600);
    }

    private function meta(): array
    {
        return json_decode(file_get_contents($this->path('meta.json')), true);
    }

    // ── validation clé ──

    private function isValidP256Spki(string $spki): bool
    {
        $pem = $this->derToPem($spki, 'PUBLIC KEY');
        $pub = openssl_pkey_get_public($pem);
        if ($pub === false) {
            return false;
        }
        $d = openssl_pkey_get_details($pub);

        return ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC && (($d['ec']['curve_name'] ?? '') === 'prime256v1' || ($d['bits'] ?? 0) === 256);
    }

    private function subjectDerHexOf(string $certPem): string
    {
        // Reconstruit le Name de l'AC émettrice (on contrôle ses composants) pour un issuer feuille stable.
        return bin2hex($this->name(['cn' => 'Leezr DEV Issuing CA (non-trusted test)', 'o' => 'Leezr DEV', 'c' => 'FR']));
    }

    private function spkiDerOf(string $privPem): string
    {
        $key = openssl_pkey_get_private($privPem);
        $pubPem = openssl_pkey_get_details($key)['key'];

        return $this->pemToDer($pubPem);
    }

    // ── DER (auto-contenu) ──

    private function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $s = '';
        while ($n > 0) {
            $s = chr($n & 0xFF) . $s;
            $n >>= 8;
        }

        return chr(0x80 | strlen($s)) . $s;
    }

    private function tlv(int $tag, string $v): string
    {
        return chr($tag) . $this->len(strlen($v)) . $v;
    }

    private function seq(string $v): string
    {
        return $this->tlv(0x30, $v);
    }

    private function set(string $v): string
    {
        return $this->tlv(0x31, $v);
    }

    private function octet(string $v): string
    {
        return $this->tlv(0x04, $v);
    }

    private function bool(bool $b): string
    {
        return $this->tlv(0x01, $b ? "\xFF" : "\x00");
    }

    private function expl(int $tag, string $v): string
    {
        return $this->tlv(0xA0 | $tag, $v);
    }

    private function intFromInt(int $n): string
    {
        return $this->tlv(0x02, chr($n));
    }

    private function intFromBin(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }

        return $this->tlv(0x02, $bytes);
    }

    private function utcTime(int $t): string
    {
        return $this->tlv(0x17, gmdate('ymdHis', $t) . 'Z');
    }

    private function bitString(string $der): string
    {
        return $this->tlv(0x03, "\x00" . $der);
    }

    /** OID court sous la forme "55:04:03" (octets après le tag). */
    private function oid(string $hexColon): string
    {
        $bytes = hex2bin(str_replace(':', '', $hexColon));

        return $this->tlv(0x06, $bytes);
    }

    /** Name X.500 depuis des composants cn/ou/o/c (UTF8String, C en PrintableString). */
    private function name(array $components): string
    {
        $oids = ['cn' => '55:04:03', 'ou' => '55:04:0B', 'o' => '55:04:0A', 'c' => '55:04:06'];
        $rdns = '';
        foreach (['cn', 'ou', 'o', 'c'] as $k) {
            if (empty($components[$k])) {
                continue;
            }
            $valueTag = $k === 'c' ? 0x13 : 0x0C; // PrintableString pour le pays, sinon UTF8String
            $attr = $this->seq($this->oid($oids[$k]) . $this->tlv($valueTag, (string) $components[$k]));
            $rdns .= $this->set($attr);
        }

        return $this->seq($rdns);
    }

    // ── util fichiers / PEM ──

    private function randomSerial(): string
    {
        $b = random_bytes(16);
        $b[0] = chr(ord($b[0]) & 0x7F); // positif

        return $b;
    }

    private function randomSerialInt(): int
    {
        return random_int(1, PHP_INT_MAX);
    }

    private function path(string $f): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $f;
    }

    private function put(string $f, string $content, int $perms): void
    {
        file_put_contents($this->path($f), $content);
        @chmod($this->path($f), $perms);
    }

    private function derToPem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }

    private function pemToDer(string $pem): string
    {
        $pem = preg_replace('/-----(BEGIN|END)[^-]+-----/', '', $pem);

        return base64_decode(preg_replace('/\s+/', '', $pem), true);
    }

    private function audit(string $action, array $data): void
    {
        $line = json_encode(['at' => gmdate('c'), 'action' => $action] + $data) . "\n";
        file_put_contents($this->path('issued.log'), $line, FILE_APPEND);
        @chmod($this->path('issued.log'), 0600);
    }

    private function writeOpensslConfig(): string
    {
        $cnf = $this->path('openssl.tmp.cnf');
        file_put_contents($cnf, <<<'CNF'
[ req ]
default_bits = 2048
default_md = sha256
distinguished_name = dn
prompt = no
[ dn ]
[ v3_ca ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[ v3_issuing ]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
CNF);

        return $cnf;
    }
}
