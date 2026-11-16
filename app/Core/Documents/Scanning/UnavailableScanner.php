<?php

namespace App\Core\Documents\Scanning;

/**
 * ADR-796.1 — default driver when no engine is configured : every analysis is INDETERMINATE, so no
 * quarantined file can ever be used. It never claims an analysis took place.
 */
final class UnavailableScanner implements FileScanner
{
    public function engine(): string
    {
        return 'none';
    }

    public function scan(string $absolutePath): ScanResult
    {
        return ScanResult::error($this->engine(), 'no_engine_configured');
    }
}
