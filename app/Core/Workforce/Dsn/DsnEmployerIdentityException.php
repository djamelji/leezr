<?php

namespace App\Core\Workforce\Dsn;

/**
 * ADR-784 — the employer identity required by a DSN is missing or invalid. Raised BEFORE any file or
 * declaration is written : nothing incomplete is produced. `fields` lists the DSN fields at stake,
 * `kind` tells whether they are missing or malformed.
 */
class DsnEmployerIdentityException extends \DomainException
{
    /** @param array<string, string> $issues field => 'missing' | 'invalid' */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('DSN employer identity incomplete or invalid: '.implode(', ', array_map(fn ($f, $k) => "{$f} ({$k})", array_keys($issues), $issues)));
    }
}
