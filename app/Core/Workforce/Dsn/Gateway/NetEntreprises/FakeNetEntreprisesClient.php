<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

/**
 * Fake Net-Entreprises client for testing.
 *
 * Supports configurable scenarios to test all gateway code paths
 * without any real HTTP calls.
 *
 * Usage:
 *   $fake = new FakeNetEntreprisesClient('success');
 *   $fake = new FakeNetEntreprisesClient('authentication_failed');
 *   $fake = FakeNetEntreprisesClient::pendingThenAccepted(2);
 *
 * Sprint 7.2 — ADR-530
 */
class FakeNetEntreprisesClient implements NetEntreprisesClientInterface
{
    private int $pollCount = 0;

    private int $pendingPolls;

    /**
     * @param  string  $scenario  One of: success, authentication_failed, network_timeout,
     *                            duplicate_submission, technical_rejection, business_rejection,
     *                            pending_then_accepted, pending_then_rejected
     */
    public function __construct(
        private string $scenario = 'success',
        int $pendingPolls = 1,
    ) {
        $this->pendingPolls = $pendingPolls;
    }

    public static function pendingThenAccepted(int $pendingPolls = 1): self
    {
        return new self('pending_then_accepted', $pendingPolls);
    }

    public static function pendingThenRejected(int $pendingPolls = 1): self
    {
        return new self('pending_then_rejected', $pendingPolls);
    }

    public function submit(string $payload, array $credentials, array $metadata): NetEntreprisesSubmitResponse
    {
        return match ($this->scenario) {
            'success', 'pending_then_accepted', 'pending_then_rejected' => $this->submitSuccess($metadata),
            'authentication_failed' => $this->submitAuthFailed(),
            'network_timeout' => $this->submitNetworkTimeout(),
            'duplicate_submission' => $this->submitDuplicate($metadata),
            'technical_rejection' => $this->submitTechnicalRejection(),
            default => throw new \InvalidArgumentException("Unknown scenario: {$this->scenario}"),
        };
    }

    public function poll(string $submissionReference, array $credentials): NetEntreprisesPollResponse
    {
        return match ($this->scenario) {
            'success' => NetEntreprisesPollResponse::accepted(
                report: 'workforce/dsn/reports/cco-fake.xml',
                metadata: ['fake' => true],
            ),
            'pending_then_accepted' => $this->pollPendingThenAccepted(),
            'pending_then_rejected' => $this->pollPendingThenRejected(),
            'business_rejection' => $this->pollBusinessRejection(),
            'authentication_failed' => throw new \DomainException('authentication_failed: Invalid credentials'),
            'network_timeout' => throw new \RuntimeException('network_timeout: Connection timed out'),
            default => NetEntreprisesPollResponse::pending(),
        };
    }

    // ─── Submit scenarios ───

    private function submitSuccess(array $metadata): NetEntreprisesSubmitResponse
    {
        $reference = 'NE-' . ($metadata['company_id'] ?? '0')
            . '-' . ($metadata['period_month'] ?? 'unknown')
            . '-' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        return NetEntreprisesSubmitResponse::accepted(
            reference: $reference,
            receipt: '<AEE><status>OK</status></AEE>',
            rawResponse: ['type' => 'AEE', 'status' => 'OK'],
        );
    }

    private function submitAuthFailed(): NetEntreprisesSubmitResponse
    {
        return NetEntreprisesSubmitResponse::rejected(
            errorCode: 'authentication_failed',
            errorMessage: 'Authentication failed: invalid credentials.',
            rawResponse: ['type' => 'error', 'code' => 'AUTH_INVALID'],
        );
    }

    private function submitNetworkTimeout(): NetEntreprisesSubmitResponse
    {
        return NetEntreprisesSubmitResponse::transportError(
            errorMessage: 'Connection timed out after 30 seconds.',
            rawResponse: ['type' => 'transport_error'],
        );
    }

    private function submitDuplicate(array $metadata): NetEntreprisesSubmitResponse
    {
        return NetEntreprisesSubmitResponse::rejected(
            errorCode: 'duplicate_submission',
            errorMessage: 'Declaration already submitted for this period.',
            rawResponse: ['type' => 'error', 'code' => 'DUPLICATE'],
        );
    }

    private function submitTechnicalRejection(): NetEntreprisesSubmitResponse
    {
        return NetEntreprisesSubmitResponse::rejected(
            errorCode: 'technical_rejection',
            errorMessage: 'ARE: File encoding is not ISO-8859-1.',
            rawResponse: ['type' => 'ARE', 'reason' => 'ENCODING_ERROR'],
        );
    }

    // ─── Poll scenarios ───

    private function pollPendingThenAccepted(): NetEntreprisesPollResponse
    {
        $this->pollCount++;

        if ($this->pollCount <= $this->pendingPolls) {
            return NetEntreprisesPollResponse::pending(['attempt' => $this->pollCount]);
        }

        return NetEntreprisesPollResponse::accepted(
            report: 'workforce/dsn/reports/cco-fake.xml',
            metadata: ['cnav' => ['status' => 'ok'], 'urssaf' => ['status' => 'ok']],
            rawResponse: ['type' => 'CCO'],
        );
    }

    private function pollPendingThenRejected(): NetEntreprisesPollResponse
    {
        $this->pollCount++;

        if ($this->pollCount <= $this->pendingPolls) {
            return NetEntreprisesPollResponse::pending(['attempt' => $this->pollCount]);
        }

        return NetEntreprisesPollResponse::rejected(
            errorCode: 'business_rejection',
            errorMessage: 'BAN: SIRET mismatch with registered entity.',
            report: 'workforce/dsn/reports/ban-fake.xml',
            rawResponse: ['type' => 'BAN'],
        );
    }

    private function pollBusinessRejection(): NetEntreprisesPollResponse
    {
        return NetEntreprisesPollResponse::rejected(
            errorCode: 'business_rejection',
            errorMessage: 'BAN: NIR validation failed for employee #42.',
            report: 'workforce/dsn/reports/ban-fake.xml',
            rawResponse: ['type' => 'BAN', 'reason' => 'NIR_INVALID'],
        );
    }
}
