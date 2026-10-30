<?php

namespace App\Core\Crypto;

use Illuminate\Encryption\Encrypter;

/**
 * W-SIGNATURE-7/8 (ADR-659/660) — Autorité de Certification INTERNE, STRICTEMENT DÉDIÉE À DEV/TEST.
 *
 * ⚠️ LIMITES DE CONFIANCE (non négociables, reflétées honnêtement dans tous les rapports) :
 *   - Cette AC N'EST PAS une autorité de confiance publique, N'EST PAS reconnue, N'EST PAS qualifiée eIDAS.
 *   - Elle est DISTINCTE du cachet organisationnel serveur Leezr (CryptographicSealService).
 *   - Un certificat émis ici prouve uniquement la liaison « clé publique ↔ identité DÉCLARÉE », pas une
 *     vérification d'identité réglementaire. Les rapports doivent l'afficher explicitement.
 *
 * ARCHITECTURE (adaptée à un serveur DEV mono-machine) :
 *   - Racine auto-signée → signe l'AC émettrice (pathlen:0) → l'émettrice signe les certificats FEUILLE.
 *   - ⚠️ La clé racine est conservée CHIFFRÉE SUR LE MÊME SERVEUR applicatif. Ce N'EST PAS une racine
 *     « hors ligne » au sens strict (pas de HSM, pas d'air-gap) : c'est une limite assumée de l'AC DEV,
 *     bloquante pour toute prétention de confiance renforcée (cf. ADR-660 §6, conditions PROD).
 *   - Les clés privées d'AC sont CHIFFRÉES AU REPOS (secret DÉDIÉ `signature.dev_ca.key` si fourni,
 *     sinon repli APP_KEY), stockées hors web-root (storage/app/private/dev-ca, perms 0600), JAMAIS
 *     exposées au frontend ni à un contrôleur HTTP.
 *   - Journal d'émission/révocation append-only (issued.log) en complément de la traçabilité DB.
 *   - Validation de chaîne interne vérifiable (verifyChain) : leaf → émettrice → racine + fenêtres de
 *     validité. La chaîne reste NON reconnue publiquement (recognized_trust_chain=false).
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
        $issuingKey = openssl_pkey_get_private($this->encrypter()->decryptString(file_get_contents($this->path('issuing.key.enc'))));
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

        // SKI = RFC 5280 méthode (1) : SHA-1 des octets de la clé publique (cohérent avec l'AC).
        $ski = $this->keyIdentifier($spki);
        $akiKeyId = hex2bin($meta['issuing_ski_hex']);

        $extensions = $this->expl(3, $this->seq(
            // basicConstraints critical : cA = FALSE (SEQUENCE vide)
            $this->seq($this->oid('55:1D:13') . $this->bool(true) . $this->octet($this->seq(''))) .
            // keyUsage critical : digitalSignature + nonRepudiation (contentCommitment)
            $this->seq($this->oid('55:1D:0F') . $this->bool(true) . $this->octet("\x03\x02\x06\xC0")) .
            // extendedKeyUsage (non critique) : id-kp-emailProtection (1.3.6.1.5.5.7.3.4) — EKU de signature
            // largement reconnue (S/MIME / signature de document). NB : id-kp-documentSigning (…3.36, RFC 9336)
            // est rejeté « corrupted » par le NSS de poppler → on retient emailProtection, interopérable.
            $this->seq($this->oid('55:1D:25') . $this->octet($this->seq("\x06\x08\x2B\x06\x01\x05\x05\x07\x03\x04"))) .
            // subjectKeyIdentifier
            $this->seq($this->oid('55:1D:0E') . $this->octet($this->octet($ski))) .
            // authorityKeyIdentifier (keyIdentifier [0] = SKI RÉEL de l'AC émettrice)
            $this->seq($this->oid('55:1D:23') . $this->octet($this->seq("\x80" . $this->len(strlen($akiKeyId)) . $akiKeyId)))
        ));

        $tbs = $this->seq(
            $this->expl(0, $this->intFromInt(2)) .           // version v3
            $this->intFromBin($serial) .                      // serialNumber
            $this->seq(self::ECDSA_SHA256_OID) .              // signatureAlgorithm (tbs)
            $issuerDer .                                      // issuer = sujet de l'AC émettrice
            $this->seq($this->validityTime($notBefore) . $this->validityTime($notAfter)) .
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

    /** Chiffreur des clés d'AC : secret DÉDIÉ si fourni (découplé d'APP_KEY), sinon repli APP_KEY. */
    private function encrypter(): \Illuminate\Contracts\Encryption\Encrypter
    {
        $key = (string) config('signature.dev_ca.key', '');
        if ($key !== '') {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

            return new Encrypter($raw, config('app.cipher', 'AES-256-CBC'));
        }

        return app('encrypter'); // repli APP_KEY (dépendance documentée — à séparer, ADR-660 §6)
    }

    /**
     * Validation de la CHAÎNE INTERNE (feuille → émettrice → racine) : signatures + fenêtres de validité.
     * La chaîne reste NON reconnue publiquement — c'est une vérification de cohérence interne, pas un
     * ancrage de confiance.
     *
     * @return array{valid:bool, checks:array<string,bool>, recognized_trust_chain:false}
     */
    public function verifyChain(string $leafPem): array
    {
        $this->ensureInitialized();
        $issuingPem = file_get_contents($this->path('issuing.crt'));
        $rootPem = file_get_contents($this->path('root.crt'));
        $now = time();

        $checks = [
            'leaf_signed_by_issuing' => @openssl_x509_verify($leafPem, openssl_pkey_get_public($issuingPem)) === 1,
            'issuing_signed_by_root' => @openssl_x509_verify($issuingPem, openssl_pkey_get_public($rootPem)) === 1,
            'root_self_signed' => @openssl_x509_verify($rootPem, openssl_pkey_get_public($rootPem)) === 1,
            'leaf_time_valid' => $this->withinValidity($leafPem, $now),
            'issuing_time_valid' => $this->withinValidity($issuingPem, $now),
            'root_time_valid' => $this->withinValidity($rootPem, $now),
        ];

        return ['valid' => ! in_array(false, $checks, true), 'checks' => $checks, 'recognized_trust_chain' => false];
    }

    /** État de l'AC (dates d'expiration racine/émettrice) — pour surveiller l'expiration de l'AC (§6). */
    public function caStatus(): array
    {
        $this->ensureInitialized();
        $root = openssl_x509_parse(file_get_contents($this->path('root.crt')));
        $iss = openssl_x509_parse(file_get_contents($this->path('issuing.crt')));

        return [
            'initialized' => true,
            'root_not_after' => $root['validTo_time_t'] ?? null,
            'issuing_not_after' => $iss['validTo_time_t'] ?? null,
            'issuance_enabled' => $this->issuanceEnabled(),
            'dedicated_encryption_key' => config('signature.dev_ca.key', '') !== '',
            'recognized_trust_chain' => false,
            'offline_root' => false, // honnête : racine chiffrée sur le serveur, PAS hors ligne
        ];
    }

    private function withinValidity(string $pem, int $now): bool
    {
        $p = openssl_x509_parse($pem);

        return $p && $now >= ($p['validFrom_time_t'] ?? PHP_INT_MAX) && $now <= ($p['validTo_time_t'] ?? 0);
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

        // Sujet DER RÉEL de l'AC émettrice (byte-identique → issuer des feuilles) + SKI RÉEL (RFC méthode 1,
        // SHA-1 de la clé publique — identique à `subjectKeyIdentifier=hash` d'OpenSSL → AKI cohérent).
        $issuingSubjectHex = bin2hex($this->extractSubjectDer($this->pemToDer($issPem)));
        $issuingSpki = $this->spkiDerOf($issKeyPem);
        $issuingSkiHex = bin2hex($this->keyIdentifier($issuingSpki));

        // Écriture : clés CHIFFRÉES au repos, certs en clair, perms strictes.
        $this->put('root.key.enc', $this->encrypter()->encryptString($rootKeyPem), 0600);
        $this->put('root.crt', $rootPem, 0600);
        $this->put('issuing.key.enc', $this->encrypter()->encryptString($issKeyPem), 0600);
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

        // B3 : exiger STRICTEMENT prime256v1 (NIST P-256). Une autre courbe de 256 bits (secp256k1,
        // brainpoolP256) est refusée, car l'algorithme signé annonce ecdsa-with-SHA256 sur P-256.
        return ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? '') === 'prime256v1';
    }

    // ── lecture ASN.1 (extraction de champs réels depuis un cert/SPKI DER) ──

    /** Lit un TLV à l'offset donné : [tag, content_off, len, total]. */
    private function readTlv(string $der, int $off): array
    {
        $tag = ord($der[$off]);
        $i = $off + 1;
        $len = ord($der[$i]);
        $i++;
        if ($len & 0x80) {
            $n = $len & 0x7F;
            $len = 0;
            for ($k = 0; $k < $n; $k++) {
                $len = ($len << 8) | ord($der[$i]);
                $i++;
            }
        }

        return ['tag' => $tag, 'content_off' => $i, 'len' => $len, 'total' => ($i - $off) + $len];
    }

    /** Extrait le DER BRUT du champ `subject` (Name) d'un certificat — byte-identique (B4). */
    private function extractSubjectDer(string $certDer): string
    {
        $outer = $this->readTlv($certDer, 0);                 // Certificate SEQUENCE
        $tbs = $this->readTlv($certDer, $outer['content_off']); // TBSCertificate SEQUENCE
        $off = $tbs['content_off'];
        $end = $tbs['content_off'] + $tbs['len'];
        $fields = [];
        while ($off < $end) {
            $t = $this->readTlv($certDer, $off);
            $fields[] = ['off' => $off, 'total' => $t['total'], 'tag' => $t['tag']];
            $off += $t['total'];
        }
        // [ [0]version? , serial , sigAlg , issuer , validity , subject , spki , ... ]
        $idx = ($fields[0]['tag'] === 0xA0) ? 5 : 4;
        $f = $fields[$idx];

        return substr($certDer, $f['off'], $f['total']);
    }

    /** SKI RFC 5280 méthode (1) : SHA-1 des octets de la clé publique (subjectPublicKey du SPKI). */
    private function keyIdentifier(string $spkiDer): string
    {
        $seq = $this->readTlv($spkiDer, 0);                       // SPKI SEQUENCE
        $alg = $this->readTlv($spkiDer, $seq['content_off']);     // AlgorithmIdentifier SEQUENCE
        $bit = $this->readTlv($spkiDer, $seq['content_off'] + $alg['total']); // BIT STRING
        // 1er octet du BIT STRING = unused-bits (0) ; le reste = clé publique brute.
        $pub = substr($spkiDer, $bit['content_off'] + 1, $bit['len'] - 1);

        return sha1($pub, true); // 20 octets
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

    /** M2 : UTCTime (tag 0x17, année 2 chiffres) < 2050, sinon GeneralizedTime (tag 0x18, année 4 chiffres). */
    private function validityTime(int $t): string
    {
        $year = (int) gmdate('Y', $t);
        if ($year < 2050) {
            return $this->tlv(0x17, gmdate('ymdHis', $t) . 'Z');
        }

        return $this->tlv(0x18, gmdate('YmdHis', $t) . 'Z');
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
