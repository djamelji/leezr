<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

/**
 * Contract for Net-Entreprises HTTP client.
 *
 * This interface isolates all HTTP/transport concerns from the gateway.
 * The real implementation (Sprint 7.3+) will handle authentication,
 * GZIP compression, and ISO-8859-1 encoding.
 *
 * For testing, use FakeNetEntreprisesClient.
 *
 * Sprint 7.2 — ADR-530
 */
interface NetEntreprisesClientInterface
{
    /**
     * Submit a DSN file to Net-Entreprises.
     *
     * @param  string  $payload  Raw DSN file content (already ISO-8859-1 encoded)
     * @param  array  $credentials  Decrypted credentials: ne_siret, ne_nom, ne_prenom, ne_password
     * @param  array  $metadata  Context: company_id, period_month, declaration_type, payload_hash
     * @return NetEntreprisesSubmitResponse  Synchronous AEE/ARE response
     *
     * @throws \RuntimeException  On retryable transport errors
     */
    public function submit(string $payload, array $credentials, array $metadata): NetEntreprisesSubmitResponse;

    /**
     * Poll for declaration status (CCO/BAN/CRM).
     *
     * @param  string  $submissionReference  idFlux returned by submit (23 chars)
     * @param  array  $credentials  Decrypted credentials for authentication
     * @return NetEntreprisesPollResponse  Asynchronous status response
     *
     * @throws \RuntimeException  On retryable transport errors
     */
    public function poll(string $submissionReference, array $credentials): NetEntreprisesPollResponse;
}
