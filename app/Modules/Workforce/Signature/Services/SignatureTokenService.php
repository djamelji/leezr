<?php

namespace App\Modules\Workforce\Signature\Services;

/**
 * W-SIGNATURE-1 (ADR-651) — tokens d'accès signataire.
 *
 * Token opaque haute entropie (256 bits). On ne stocke JAMAIS le token en clair : seul son hash
 * sha256 est persisté, et la comparaison est constante (hash_equals) pour éviter les attaques
 * temporelles. Les liens sont donc non énumérables, révocables et limités dans le temps.
 */
class SignatureTokenService
{
    /** @return array{raw:string, hash:string} */
    public static function generate(): array
    {
        $raw = bin2hex(random_bytes(32)); // 256 bits d'entropie.

        return ['raw' => $raw, 'hash' => self::hash($raw)];
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** Comparaison constante contre le hash stocké. */
    public static function matches(string $providedRaw, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($providedRaw));
    }

    /** URL publique du parcours signataire (lien d'invitation). */
    public static function signerUrl(string $rawToken): string
    {
        return rtrim(config('app.url'), '/') . '/sign/' . $rawToken;
    }
}
