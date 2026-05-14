<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

use App\Core\Workforce\Dsn\Gateway\DsnGatewayInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingResult;
use App\Core\Workforce\Dsn\Gateway\DsnSubmissionResult;
use Illuminate\Support\Facades\Storage;

/**
 * Net-Entreprises DSN gateway.
 *
 * Implements both submit and poll contracts. Delegates all HTTP/transport
 * concerns to the injected NetEntreprisesClientInterface.
 *
 * This class is adapter-only: it maps client responses to gateway DTOs,
 * reads DSN files from storage, archives XML returns, and filters secrets.
 *
 * Sprint 7.2 — ADR-530, Sprint 8.2 — ADR-535
 */
class NetEntreprisesDsnGateway implements DsnGatewayInterface, DsnPollingInterface
{
    /** Fields to strip from rawResponse before returning. */
    private const SECRET_FIELDS = [
        'password', 'token', 'jeton', 'motdepasse',
        'ne_password', 'secret', 'credential',
    ];

    public function __construct(
        private NetEntreprisesClientInterface $client,
        private DsnCredentialService $credentialService,
        private string $disk = 'local',
    ) {}

    public function submit(string $filePath, array $metadata): DsnSubmissionResult
    {
        // 1. Validate credentials
        if (! $this->credentialService->hasCredentials()) {
            return DsnSubmissionResult::failure('credentials_missing: DSN credentials not configured.');
        }

        $credentials = $this->credentialService->getCredentials();
        $formatErrors = $this->credentialService->validateFormats();

        if (! empty($formatErrors)) {
            $errorDetail = implode('; ', $formatErrors);

            return DsnSubmissionResult::failure("credentials_invalid: {$errorDetail}");
        }

        // 2. Read DSN file
        $storage = Storage::disk($this->disk);

        if (! $storage->exists($filePath)) {
            return DsnSubmissionResult::failure("DSN file not found: {$filePath}");
        }

        $payload = $storage->get($filePath);

        // 3. Delegate to client
        try {
            $response = $this->client->submit($payload, $credentials, $metadata);
        } catch (\RuntimeException $e) {
            // Retryable transport error — propagate for retry loop
            throw $e;
        } catch (\DomainException $e) {
            // Non-retryable business/auth error
            return DsnSubmissionResult::failure(
                $e->getMessage(),
                $this->filterSecrets(['exception' => get_class($e)]),
            );
        }

        // 4. Map client response to gateway result
        if ($response->success) {
            // Archive AEE receipt if available
            $receiptRaw = $this->filterSecrets($response->rawResponse);

            if ($response->receipt && $response->reference) {
                $receiptPath = $this->archiveReturn($response->reference, $response->receipt, 'aee');
                $receiptRaw['archived_receipt_path'] = $receiptPath;
            }

            return DsnSubmissionResult::success(
                $response->reference,
                $receiptRaw,
            );
        }

        // Non-retryable failure
        if (! $response->retryable) {
            return DsnSubmissionResult::failure(
                "{$response->errorCode}: {$response->errorMessage}",
                $this->filterSecrets($response->rawResponse),
            );
        }

        // Retryable failure — throw RuntimeException for retry loop
        throw new \RuntimeException(
            "{$response->errorCode}: {$response->errorMessage}"
        );
    }

    public function poll(string $submissionReference): DsnPollingResult
    {
        if (! $this->credentialService->hasCredentials()) {
            return DsnPollingResult::rejected(
                'credentials_missing',
                'DSN credentials not configured.',
            );
        }

        $credentials = $this->credentialService->getCredentials();

        try {
            $response = $this->client->poll($submissionReference, $credentials);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\DomainException $e) {
            return DsnPollingResult::rejected(
                'polling_error',
                $e->getMessage(),
            );
        }

        // Archive raw XML return if terminal (CCO/BAN)
        $archivedReportPath = null;

        if ($response->terminal && $response->report) {
            $archivedReportPath = $this->archiveReturn(
                $submissionReference,
                $response->report,
                $response->gatewayStatus === 'cco_accepted' ? 'cco' : 'ban',
            );
        }

        return match ($response->gatewayStatus) {
            'pending' => DsnPollingResult::pending(
                $this->filterSecrets($response->rawResponse),
            ),
            'cco_accepted' => DsnPollingResult::accepted(
                businessReportPath: $archivedReportPath,
                metadata: $response->metadata,
                rawResponse: $this->filterSecrets($response->rawResponse),
            ),
            'ban_rejected' => DsnPollingResult::rejected(
                errorCode: $response->errorCode ?? 'business_rejection',
                errorMessage: $response->errorMessage ?? 'Business rejection received.',
                businessReportPath: $archivedReportPath,
                rawResponse: $this->filterSecrets($response->rawResponse),
            ),
            default => DsnPollingResult::pending(
                $this->filterSecrets($response->rawResponse),
            ),
        };
    }

    /**
     * Archive a gateway return (CCO/BAN) XML to disk.
     *
     * Stores the raw XML body and returns the storage path.
     *
     * Sprint 8.2 — ADR-535
     */
    private function archiveReturn(string $submissionReference, string $xmlBody, string $type): string
    {
        $safeRef = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $submissionReference);
        $timestamp = now()->format('Ymd_His');
        $path = "workforce/dsn/returns/{$safeRef}/{$type}_{$timestamp}.xml";

        Storage::disk($this->disk)->put($path, $xmlBody);

        return $path;
    }

    /**
     * Strip secret-like fields from raw response data.
     */
    private function filterSecrets(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_FIELDS, true)) {
                continue;
            }

            $filtered[$key] = is_array($value) ? $this->filterSecrets($value) : $value;
        }

        return $filtered;
    }
}
