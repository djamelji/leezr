<?php

namespace App\Core\Requirements;

/** ADR-791 — obligation levels, ordered. Only `default` and above make the target required. */
final class RequirementLevel
{
    public const RECOMMENDED = 'recommended';
    public const DEFAULT = 'default';
    public const CONFIGURABLE_REQUIRED = 'configurable_required';
    public const IMPERATIVE = 'imperative';

    private const RANK = [self::RECOMMENDED => 1, self::DEFAULT => 2, self::CONFIGURABLE_REQUIRED => 3, self::IMPERATIVE => 4];

    public static function isValid(?string $level): bool
    {
        return $level !== null && isset(self::RANK[$level]);
    }

    public static function rank(?string $level): int
    {
        return self::RANK[$level] ?? 0;
    }

    public static function requires(?string $level): bool
    {
        return self::rank($level) >= self::RANK[self::DEFAULT];
    }
}
