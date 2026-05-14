<?php

namespace App\Core\Workforce\Dsn\Gateway;

/**
 * Contract for polling DSN declaration status from external gateways.
 *
 * Implementations check the status of a previously submitted declaration
 * using the gateway's submission reference (idFlux for Net-Entreprises).
 *
 * Sprint 7.1 — ADR-529
 */
interface DsnPollingInterface
{
    /**
     * Poll the status of a submitted DSN declaration.
     *
     * @param  string  $submissionReference  Gateway reference (idFlux for NE, 23 chars)
     * @return DsnPollingResult
     */
    public function poll(string $submissionReference): DsnPollingResult;
}
