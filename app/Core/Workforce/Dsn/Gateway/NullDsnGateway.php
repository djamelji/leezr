<?php

namespace App\Core\Workforce\Dsn\Gateway;

/**
 * Null DSN gateway — always returns success.
 *
 * Used as default in dev/test environments where no real
 * transmission target exists.
 *
 * Sprint 6.5 — ADR-523
 */
class NullDsnGateway implements DsnGatewayInterface
{
    public function submit(string $filePath, array $metadata): DsnSubmissionResult
    {
        $reference = 'NULL-' . ($metadata['company_id'] ?? '0')
            . '-' . ($metadata['period_month'] ?? 'unknown')
            . '-' . time();

        return DsnSubmissionResult::success($reference);
    }
}
