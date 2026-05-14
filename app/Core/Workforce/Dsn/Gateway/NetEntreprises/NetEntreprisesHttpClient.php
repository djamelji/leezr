<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real HTTP client for Net-Entreprises API DSN.
 *
 * Implements the 3-step flow:
 *   1. Authenticate → obtain jeton (token)
 *   2. Deposit DSN → send gzipped file with token → receive AEE/ARE
 *   3. Consult → query status by idFlux → receive CCO/BAN/EN_COURS
 *
 * All HTTP details are encapsulated here. The gateway adapter
 * (NetEntreprisesDsnGateway) handles business logic mapping.
 *
 * Sprint 8.1 — ADR-534
 */
class NetEntreprisesHttpClient implements NetEntreprisesClientInterface
{
    /** Cached auth token (in-memory, per-request lifecycle). */
    private ?string $cachedToken = null;

    private ?int $tokenExpiresAt = null;

    public function submit(string $payload, array $credentials, array $metadata): NetEntreprisesSubmitResponse
    {
        $token = $this->authenticate($credentials);

        $depositUrl = config('workforce.dsn.ne_deposit_url');
        $timeout = config('workforce.dsn.ne_timeout_seconds', 30);
        $retries = config('workforce.dsn.ne_retry_attempts', 2);

        // Gzip the payload
        $gzipped = gzencode($payload, 9);

        if ($gzipped === false) {
            throw new \RuntimeException('Failed to gzip DSN payload.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type' => 'text/plain',
                'Content-Encoding' => 'gzip',
                'User-Agent' => 'Leezr-DSN/1.0',
            ])
                ->timeout($timeout)
                ->retry($retries, 1000, function (\Exception $e) {
                    // Only retry on transport errors, not 4xx
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->withBody($gzipped, 'text/plain')
                ->post($depositUrl);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException(
                "Transport error during DSN deposit: {$e->getMessage()}",
                0,
                $e,
            );
        }

        // 401 = token expired or invalid credentials
        if ($response->status() === 401) {
            $this->invalidateToken();

            throw new \DomainException(
                'Authentication failed: invalid or expired token (HTTP 401).'
            );
        }

        $body = $response->body();
        $rawResponse = $this->parseXmlResponse($body);

        // Success: AEE received
        if ($response->successful()) {
            $reference = $rawResponse['idFlux'] ?? $rawResponse['id-flux'] ?? null;

            if (! $reference) {
                // Try to extract from known XML patterns
                $reference = $this->extractFromXml($body, 'idFlux')
                    ?? $this->extractFromXml($body, 'id-flux');
            }

            return NetEntreprisesSubmitResponse::accepted(
                reference: $reference ?? ('NE-' . substr(md5($body), 0, 20)),
                receipt: $body,
                rawResponse: $rawResponse,
            );
        }

        // Non-retryable rejection (ARE)
        $errorCode = $rawResponse['code-erreur'] ?? $rawResponse['codeErreur'] ?? 'unknown';
        $errorMessage = $rawResponse['message'] ?? $rawResponse['libelle'] ?? "HTTP {$response->status()}";

        return NetEntreprisesSubmitResponse::rejected(
            errorCode: (string) $errorCode,
            errorMessage: (string) $errorMessage,
            rawResponse: $rawResponse,
        );
    }

    public function poll(string $submissionReference, array $credentials): NetEntreprisesPollResponse
    {
        $token = $this->authenticate($credentials);

        $statusUrl = config('workforce.dsn.ne_status_url');
        $timeout = config('workforce.dsn.ne_timeout_seconds', 30);

        try {
            $response = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type' => 'application/xml',
                'User-Agent' => 'Leezr-DSN/1.0',
            ])
                ->timeout($timeout)
                ->withBody($this->buildConsultXml($submissionReference), 'application/xml')
                ->post($statusUrl);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException(
                "Transport error during DSN status check: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if ($response->status() === 401) {
            $this->invalidateToken();

            throw new \DomainException(
                'Authentication failed during poll: invalid or expired token (HTTP 401).'
            );
        }

        $body = $response->body();
        $rawResponse = $this->parseXmlResponse($body);

        // Determine status from response
        $status = $this->resolveGatewayStatus($rawResponse, $body);

        return match ($status) {
            'cco_accepted' => NetEntreprisesPollResponse::accepted(
                report: $body, // Raw XML body — gateway will archive to disk
                metadata: $rawResponse['organismes'] ?? $rawResponse,
                rawResponse: $rawResponse,
            ),
            'ban_rejected' => NetEntreprisesPollResponse::rejected(
                errorCode: $rawResponse['code-anomalie'] ?? $rawResponse['codeAnomalie'] ?? 'business_rejection',
                errorMessage: $rawResponse['message-anomalie'] ?? $rawResponse['messageAnomalie'] ?? 'Business rejection received.',
                report: $body, // Raw XML body — gateway will archive to disk
                rawResponse: $rawResponse,
            ),
            default => NetEntreprisesPollResponse::pending(
                rawResponse: $rawResponse,
            ),
        };
    }

    /**
     * Authenticate with Net-Entreprises and obtain a token (jeton).
     *
     * Caches the token in-memory for the configured TTL.
     *
     * @throws \DomainException on authentication failure
     * @throws \RuntimeException on transport error
     */
    private function authenticate(array $credentials): string
    {
        // Return cached token if still valid
        if ($this->cachedToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }

        $authUrl = config('workforce.dsn.ne_auth_url');
        $serviceCode = config('workforce.dsn.ne_service_code', '97');
        $timeout = config('workforce.dsn.ne_timeout_seconds', 30);

        $xml = $this->buildAuthXml($credentials, $serviceCode);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/xml',
                'User-Agent' => 'Leezr-DSN/1.0',
            ])
                ->timeout($timeout)
                ->withBody($xml, 'application/xml')
                ->post($authUrl);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException(
                "Transport error during NE authentication: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (! $response->successful()) {
            $body = $response->body();
            $parsed = $this->parseXmlResponse($body);
            $errorMsg = $parsed['message'] ?? $parsed['libelle'] ?? "HTTP {$response->status()}";

            throw new \DomainException(
                "NE authentication failed: {$errorMsg}"
            );
        }

        // Extract token from response
        $body = $response->body();
        $token = $this->extractToken($body);

        if (! $token) {
            throw new \DomainException(
                'NE authentication succeeded but no token found in response.'
            );
        }

        // Cache token
        $ttlMinutes = config('workforce.dsn.ne_token_ttl_minutes', 30);
        $this->cachedToken = $token;
        $this->tokenExpiresAt = time() + ($ttlMinutes * 60) - 60; // 1 min safety margin

        return $token;
    }

    /**
     * Build authentication XML body.
     *
     * IMPORTANT: Never log the output — it contains the password in clear text.
     */
    private function buildAuthXml(array $credentials, string $serviceCode): string
    {
        $siret = htmlspecialchars($credentials['ne_siret'] ?? '', ENT_XML1, 'UTF-8');
        $nom = htmlspecialchars($credentials['ne_nom'] ?? '', ENT_XML1, 'UTF-8');
        $prenom = htmlspecialchars($credentials['ne_prenom'] ?? '', ENT_XML1, 'UTF-8');
        $password = htmlspecialchars($credentials['ne_password'] ?? '', ENT_XML1, 'UTF-8');
        $service = htmlspecialchars($serviceCode, ENT_XML1, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<identifiants>
  <siret>{$siret}</siret>
  <nom>{$nom}</nom>
  <prenom>{$prenom}</prenom>
  <motdepasse>{$password}</motdepasse>
  <service>{$service}</service>
</identifiants>
XML;
    }

    /**
     * Build consultation XML body for polling a specific flux.
     */
    private function buildConsultXml(string $idFlux): string
    {
        $escapedId = htmlspecialchars($idFlux, ENT_XML1, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<consultation>
  <idFlux>{$escapedId}</idFlux>
</consultation>
XML;
    }

    /**
     * Extract authentication token from response body.
     *
     * Tries XML parsing first, then header-style patterns.
     */
    private function extractToken(string $body): ?string
    {
        // Try XML: <jeton>...</jeton>
        $token = $this->extractFromXml($body, 'jeton');

        if ($token) {
            return $token;
        }

        // Try alternate XML: <token>...</token>
        $token = $this->extractFromXml($body, 'token');

        if ($token) {
            return $token;
        }

        // If body is plain text token (some implementations)
        $trimmed = trim($body);

        if (strlen($trimmed) > 20 && strlen($trimmed) < 500 && ! str_contains($trimmed, '<')) {
            return $trimmed;
        }

        return null;
    }

    /**
     * Parse XML response into array. Falls back to raw body on failure.
     */
    private function parseXmlResponse(string $body): array
    {
        if (empty(trim($body))) {
            return ['raw_body' => ''];
        }

        // Suppress XML errors
        $prev = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);

            if ($xml === false) {
                return ['raw_body' => substr($body, 0, 2000)];
            }

            return $this->xmlToArray($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Convert SimpleXMLElement to array recursively.
     */
    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $result = [];

        foreach ($xml->children() as $name => $child) {
            if ($child->count() > 0) {
                $result[$name] = $this->xmlToArray($child);
            } else {
                $result[$name] = (string) $child;
            }
        }

        // Include attributes
        foreach ($xml->attributes() as $name => $value) {
            $result["@{$name}"] = (string) $value;
        }

        return $result;
    }

    /**
     * Extract a value from XML string by tag name.
     */
    private function extractFromXml(string $xml, string $tagName): ?string
    {
        if (preg_match("/<{$tagName}>(.*?)<\/{$tagName}>/s", $xml, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Determine gateway status from poll response.
     */
    private function resolveGatewayStatus(array $parsed, string $rawBody): string
    {
        // Check for known status indicators
        $statusField = $parsed['statut'] ?? $parsed['status'] ?? $parsed['etat'] ?? null;

        if ($statusField) {
            $normalized = strtolower($statusField);

            if (in_array($normalized, ['accepte', 'accepted', 'ok', 'conforme'])) {
                return 'cco_accepted';
            }

            if (in_array($normalized, ['rejete', 'rejected', 'ko', 'anomalie'])) {
                return 'ban_rejected';
            }
        }

        // Check for CCO/BAN/ARS markers in raw response
        $lowerBody = strtolower($rawBody);

        if (str_contains($lowerBody, 'certificat') || str_contains($lowerBody, 'cco') || str_contains($lowerBody, 'conforme')) {
            return 'cco_accepted';
        }

        if (str_contains($lowerBody, 'bilan') && (str_contains($lowerBody, 'anomalie') || str_contains($lowerBody, 'rejet'))) {
            return 'ban_rejected';
        }

        // Default: still processing
        return 'pending';
    }

    /**
     * Invalidate cached token (e.g., after 401).
     */
    private function invalidateToken(): void
    {
        $this->cachedToken = null;
        $this->tokenExpiresAt = null;
    }
}
