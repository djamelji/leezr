<?php

namespace App\Core\Documents\Scanning;

/**
 * ADR-796.1 — TEST / DEV ADAPTER, never an analysis : it only recognises the standard EICAR test string
 * (→ infected) and a marker file content (→ error) ; any other file is reported clean under the engine
 * name `simulated`, which is recorded and shown as such. Refused in production (see FileScannerManager).
 */
final class SimulatedScanner implements FileScanner
{
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    public const FAILURE_MARKER = 'LEEZR-SIMULATED-SCAN-FAILURE';

    public function engine(): string
    {
        return 'simulated';
    }

    public function scan(string $absolutePath): ScanResult
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            return ScanResult::error($this->engine(), 'file_unreadable');
        }
        if (str_contains($content, self::EICAR)) {
            return ScanResult::infected($this->engine(), 'Eicar-Test-Signature');
        }
        if (str_contains($content, self::FAILURE_MARKER)) {
            return ScanResult::error($this->engine(), 'simulated_failure');
        }

        return ScanResult::clean($this->engine());
    }
}
