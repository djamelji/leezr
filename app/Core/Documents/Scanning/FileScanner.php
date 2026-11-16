<?php

namespace App\Core\Documents\Scanning;

/**
 * ADR-796.1 — integration contract of a malware analysis engine. An implementation NEVER returns
 * `clean` without a real engine answer ; any doubt is `error` (fail-closed).
 */
interface FileScanner
{
    /** Short engine name recorded with each verdict (traceability). */
    public function engine(): string;

    public function scan(string $absolutePath): ScanResult;
}
