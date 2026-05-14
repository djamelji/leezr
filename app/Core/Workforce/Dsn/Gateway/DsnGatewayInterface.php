<?php

namespace App\Core\Workforce\Dsn\Gateway;

/**
 * Contract for DSN transmission gateways.
 *
 * Implementations handle the actual submission of a serialized DSN file
 * to an external system (or a local stub for dev/testing).
 *
 * Sprint 6.5 — ADR-523
 */
interface DsnGatewayInterface
{
    /**
     * Submit a DSN file for transmission.
     *
     * @param  string  $filePath  Absolute path to the serialized DSN file in storage.
     * @param  array  $metadata  Context: company_id, period_month, declaration_type, payload_hash.
     * @return DsnSubmissionResult
     */
    public function submit(string $filePath, array $metadata): DsnSubmissionResult;
}
