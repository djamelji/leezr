<?php

namespace App\Core\Documents\Services;

use Illuminate\Support\Facades\Storage;

/**
 * ADR-756 (DMS M0) — intégrité des fichiers : calcul/vérification SHA-256 (streaming, sans
 * charger le fichier entier en mémoire) + contrôle d'accessibilité. Disque privé `local`.
 */
class DocumentIntegrityService
{
    public function __construct(private readonly string $disk = 'local')
    {
    }

    public function accessible(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk($this->disk)->exists($path);
    }

    /** SHA-256 du fichier (null si absent). Streaming pour supporter les gros fichiers. */
    public function hashOf(?string $path): ?string
    {
        if (! $this->accessible($path)) {
            return null;
        }

        $stream = Storage::disk($this->disk)->readStream($path);
        if ($stream === false || $stream === null) {
            return null;
        }
        $ctx = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            hash_update($ctx, $chunk);
        }
        fclose($stream);

        return hash_final($ctx);
    }

    /**
     * ADR-810.1 — hash of a file JUST WRITTEN by an upload. A failed write (`store()` returns false) or
     * an unreadable file stops the upload BEFORE any document row or request state is written : no
     * reference to a file that does not exist, no « submitted » without a file.
     */
    public function storedHash(string|false|null $path): string
    {
        $hash = is_string($path) ? $this->hashOf($path) : null;
        if ($hash === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => ['The file could not be stored. Please try again.'],
            ]);
        }

        return $hash;
    }

    /** Vérifie qu'un fichier correspond à son hash stocké. Sans hash stocké : au moins accessible. */
    public function verify(?string $path, ?string $expectedSha256): bool
    {
        if ($expectedSha256 === null || $expectedSha256 === '') {
            return $this->accessible($path);
        }
        $actual = $this->hashOf($path);

        return $actual !== null && hash_equals($expectedSha256, $actual);
    }
}
