<?php

namespace App\Core\Documents\Scanning;

/**
 * ADR-796.1 — outcome of ONE file analysis. Only `clean` allows a file to be used ; `error` (engine
 * unavailable, timeout, unreadable answer) is INDETERMINATE and treated exactly like a refusal to use.
 */
final class ScanResult
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const ERROR = 'error';

    private function __construct(public readonly string $verdict, public readonly string $engine, public readonly ?string $detail = null) {}

    public static function clean(string $engine): self
    {
        return new self(self::CLEAN, $engine);
    }

    public static function infected(string $engine, string $signature): self
    {
        return new self(self::INFECTED, $engine, mb_substr($signature, 0, 191));
    }

    public static function error(string $engine, string $reason): self
    {
        return new self(self::ERROR, $engine, mb_substr($reason, 0, 255));
    }

    public function isClean(): bool
    {
        return $this->verdict === self::CLEAN;
    }
}
