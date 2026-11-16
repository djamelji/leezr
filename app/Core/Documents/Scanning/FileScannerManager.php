<?php

namespace App\Core\Documents\Scanning;

use Illuminate\Support\Facades\Log;

/**
 * ADR-796.1 — resolves the configured engine (`documents.scanner.driver`). Default `none` = fail-closed.
 * The simulated adapter is refused in production : it silently falls back to `none` (still fail-closed)
 * and the refusal is logged — never a file declared clean without an engine.
 */
final class FileScannerManager
{
    public static function driver(?string $override = null): FileScanner
    {
        $name = $override ?? (string) config('documents.scanner.driver', 'none');
        if ($name === 'simulated' && app()->isProduction()) {
            Log::warning('[documents.scanner] simulated driver refused in production — falling back to none');
            $name = 'none';
        }

        return match ($name) {
            'clamav' => new ClamAvScanner((string) config('documents.scanner.clamav_socket', 'unix:///var/run/clamav/clamd.ctl'), (int) config('documents.scanner.timeout', 30)),
            'simulated' => new SimulatedScanner,
            default => new UnavailableScanner,
        };
    }
}
