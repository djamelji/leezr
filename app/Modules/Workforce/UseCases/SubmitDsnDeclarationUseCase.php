<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnPreflightChecker;
use App\Core\Workforce\Dsn\DsnSubmissionLockService;
use App\Core\Workforce\Dsn\Gateway\DsnGatewayInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingInterface;
use App\Core\Workforce\Dsn\Gateway\DsnPollingResult;
use App\Core\Workforce\Dsn\Gateway\DsnSubmissionResult;
use App\Core\Workforce\Dsn\Gateway\NullDsnGateway;

/**
 * Submits an exported DsnDeclaration via the configured gateway,
 * and polls for asynchronous status updates.
 *
 * Safety layer (Sprint 6.8 — ADR-526):
 *   - Pre-flight checks (DsnPreflightChecker)
 *   - Submission lock (anti-double submit)
 *   - Payload hash integrity verification
 *   - Retry policy (max_attempts, exponential backoff)
 *   - Enriched audit per attempt
 *   - Dry-run global kill-switch
 *
 * Gateway integration (Sprint 7.3 — ADR-531):
 *   - Post-submit: persist gateway tracking fields
 *   - Poll: call DsnPollingInterface, transition accepted/rejected
 *   - Audit: category workforce.dsn.submit, retryable flag
 *
 * Sprint 6.5 — ADR-523, Sprint 6.8 — ADR-526, Sprint 7.3 — ADR-531
 */
class SubmitDsnDeclarationUseCase
{
    public function __construct(
        private DsnGatewayInterface $gateway,
        private ?DsnPreflightChecker $preflightChecker = null,
        private ?DsnSubmissionLockService $lockService = null,
    ) {
        $this->preflightChecker ??= new DsnPreflightChecker();
        $this->lockService ??= new DsnSubmissionLockService();
    }

    public function execute(DsnDeclaration $declaration, int $actorId): DsnDeclaration
    {
        // --- Guard: idempotency — already submitted or terminal ---
        if (in_array($declaration->status, [
            DsnDeclaration::STATUS_SUBMITTED,
            DsnDeclaration::STATUS_ACCEPTED,
        ], true)) {
            return $declaration;
        }

        // --- Guard: must be exported ---
        if ($declaration->status !== DsnDeclaration::STATUS_EXPORTED) {
            throw new \DomainException(
                "Cannot submit DsnDeclaration: status is '{$declaration->status}', expected 'exported'."
            );
        }

        // --- Guard: file must exist ---
        if (empty($declaration->file_path)) {
            throw new \DomainException(
                'Cannot submit DsnDeclaration: no DSN file path set.'
            );
        }

        // --- Pre-flight checks ---
        $preflight = $this->preflightChecker->check($declaration);

        if (! $preflight->isReady) {
            $this->auditAttempt($declaration, $actorId, 0, 0, 'preflight_failed', implode('; ', $preflight->blockingErrors));

            throw new \DomainException(
                'dsn_preflight_failed: ' . implode('; ', $preflight->blockingErrors)
            );
        }

        // --- Payload hash integrity ---
        $this->verifyPayloadHashIntegrity($declaration);

        // --- Submission lock (anti-double submit) ---
        if (! $this->lockService->acquire($declaration->id)) {
            throw new \DomainException(
                'DSN submission already in progress for this declaration (locked).'
            );
        }

        // --- Resolve effective gateway (dry-run check) ---
        $effectiveGateway = $this->resolveGateway();

        // --- Retry loop ---
        $maxAttempts = (int) config('workforce.dsn.max_attempts', 3);
        $backoffBase = (int) config('workforce.dsn.backoff_base_seconds', 1);
        $lastException = null;

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $startTime = hrtime(true);

                try {
                    $result = $effectiveGateway->submit($declaration->file_path, [
                        'company_id' => $declaration->company_id,
                        'period_month' => $declaration->period_month,
                        'declaration_type' => $declaration->declaration_type,
                        'payload_hash' => $declaration->payload_hash,
                    ]);

                    $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                    if ($result->success) {
                        // --- Success: transition + persist gateway tracking + audit ---
                        $declaration->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
                        $declaration->submission_reference = $result->reference;
                        $declaration->submitted_by = $actorId;
                        $declaration->submitted_at = now();
                        $declaration->gateway_driver = $this->resolveDriverName($effectiveGateway);
                        $declaration->gateway_environment = config('workforce.dsn.ne_environment', 'sandbox');
                        $declaration->gateway_status = 'aee_received';
                        $declaration->attempt_count = $attempt;
                        $declaration->next_poll_at = now()->addSeconds(
                            (int) config('workforce.dsn.poll_initial_delay_seconds', 15)
                        );

                        // Store technical receipt if available
                        if (! empty($result->rawResponse['type']) && $result->rawResponse['type'] === 'AEE') {
                            $declaration->technical_receipt_path = $this->storeReceipt(
                                $declaration, $result->rawResponse, 'aee'
                            );
                        }

                        $declaration->save();

                        $this->auditAttempt(
                            $declaration, $actorId, $attempt, $durationMs, 'success', null,
                            $result->reference, $effectiveGateway, $preflight, false
                        );

                        return $declaration;
                    }

                    // --- Gateway returned failure ---
                    $this->auditAttempt(
                        $declaration, $actorId, $attempt, $durationMs, 'gateway_failure', $result->error,
                        null, $effectiveGateway, $preflight, false
                    );

                    // Business rejection = non-retryable
                    if ($this->isBusinessError($result)) {
                        throw new \DomainException(
                            "DSN gateway submission rejected (business error): {$result->error}"
                        );
                    }

                    $lastException = new \DomainException("DSN gateway submission failed: {$result->error}");

                } catch (\DomainException $e) {
                    // DomainException = non-retryable, propagate immediately
                    throw $e;
                } catch (\Throwable $e) {
                    // Network/timeout errors = retryable
                    $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                    $this->auditAttempt(
                        $declaration, $actorId, $attempt, $durationMs, 'exception',
                        $e->getMessage(), null, $effectiveGateway, $preflight, true
                    );

                    $lastException = $e;
                }

                // Backoff before next attempt (skip on last attempt)
                if ($attempt < $maxAttempts) {
                    $backoffSeconds = $backoffBase * (2 ** ($attempt - 1)); // 1s, 2s, 4s
                    usleep($backoffSeconds * 1_000_000);
                }
            }

            // All attempts exhausted
            throw new \DomainException(
                "DSN submission failed after {$maxAttempts} attempts: " . ($lastException?->getMessage() ?? 'unknown error')
            );

        } finally {
            // Always release the lock
            $this->lockService->release($declaration->id);
        }
    }

    /**
     * Poll the status of a submitted declaration.
     *
     * Calls DsnPollingInterface on the gateway and maps the result
     * to DsnDeclaration state transitions.
     *
     * Sprint 7.3 — ADR-531
     */
    public function poll(DsnDeclaration $declaration, int $actorId): DsnDeclaration
    {
        // --- Guard: must be submitted ---
        if ($declaration->status !== DsnDeclaration::STATUS_SUBMITTED) {
            if ($declaration->isTerminal()) {
                return $declaration; // Already final — noop
            }

            throw new \DomainException(
                "Cannot poll DsnDeclaration: status is '{$declaration->status}', expected 'submitted'."
            );
        }

        // --- Guard: must have submission reference ---
        if (empty($declaration->submission_reference)) {
            throw new \DomainException(
                'Cannot poll DsnDeclaration: no submission_reference.'
            );
        }

        // --- Resolve polling gateway ---
        $effectiveGateway = $this->resolveGateway();

        if (! $effectiveGateway instanceof DsnPollingInterface) {
            // NullDsnGateway doesn't implement polling — auto-accept
            return $this->markAccepted($declaration, $actorId);
        }

        // --- Poll ---
        $startTime = hrtime(true);

        try {
            $pollResult = $effectiveGateway->poll($declaration->submission_reference);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->auditPoll($declaration, $actorId, $durationMs, 'exception', $e->getMessage());

            throw $e;
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // --- Update gateway tracking ---
        $declaration->gateway_status = $pollResult->gatewayStatus;
        $declaration->last_polled_at = now();

        if ($pollResult->terminal) {
            return $this->applyTerminalPollResult($declaration, $actorId, $pollResult, $durationMs);
        }

        // --- Still pending — schedule next poll ---
        $declaration->next_poll_at = now()->addSeconds(
            (int) config('workforce.dsn.poll_interval_seconds', 300)
        );
        $declaration->save();

        $this->auditPoll($declaration, $actorId, $durationMs, 'pending');

        return $declaration;
    }

    /**
     * Mark a submitted declaration as accepted.
     */
    public function markAccepted(DsnDeclaration $declaration, int $actorId): DsnDeclaration
    {
        if ($declaration->status === DsnDeclaration::STATUS_ACCEPTED) {
            return $declaration;
        }

        $declaration->transitionTo(DsnDeclaration::STATUS_ACCEPTED);
        $declaration->gateway_status = 'cco_accepted';
        $declaration->save();

        app(AuditLogger::class)->logCompany(
            companyId: $declaration->company_id,
            action: 'dsn_declaration.accepted',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.dsn',
                    'period_month' => $declaration->period_month,
                    'submission_reference' => $declaration->submission_reference,
                ],
            ],
        );

        return $declaration;
    }

    /**
     * Mark a submitted declaration as rejected.
     */
    public function markRejected(DsnDeclaration $declaration, int $actorId, ?string $reason = null): DsnDeclaration
    {
        if ($declaration->status === DsnDeclaration::STATUS_REJECTED) {
            return $declaration;
        }

        $declaration->transitionTo(DsnDeclaration::STATUS_REJECTED);
        $declaration->gateway_status = $declaration->gateway_status ?: 'ban_rejected';
        $declaration->gateway_error_message = $reason;
        $declaration->save();

        app(AuditLogger::class)->logCompany(
            companyId: $declaration->company_id,
            action: 'dsn_declaration.rejected',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.dsn',
                    'period_month' => $declaration->period_month,
                    'submission_reference' => $declaration->submission_reference,
                    'reason' => $reason,
                ],
            ],
        );

        return $declaration;
    }

    // ── Private helpers ──

    /**
     * Verify that stored payload hash matches the DSN file on disk.
     */
    private function verifyPayloadHashIntegrity(DsnDeclaration $declaration): void
    {
        if (empty($declaration->payload_hash) || empty($declaration->file_path)) {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk(config('workforce.dsn.file_disk', 'local'));

        if (! $disk->exists($declaration->file_path)) {
            throw new \DomainException('dsn_payload_tampered: DSN file missing at expected path.');
        }

        $recomputedHash = hash('sha256', $disk->get($declaration->file_path));

        if ($recomputedHash !== $declaration->payload_hash) {
            throw new \DomainException('dsn_payload_tampered: payload hash does not match stored file.');
        }
    }

    /**
     * Resolve the effective gateway — dry-run forces NullDsnGateway.
     */
    private function resolveGateway(): DsnGatewayInterface
    {
        if (! config('workforce.dsn.submit_enabled', false)) {
            return new NullDsnGateway();
        }

        return $this->gateway;
    }

    /**
     * Determine if a gateway failure is a business error (non-retryable).
     */
    private function isBusinessError(DsnSubmissionResult $result): bool
    {
        if ($result->success) {
            return false;
        }

        $rawStatus = $result->rawResponse['status'] ?? '';
        $rawCode = $result->rawResponse['error_code'] ?? '';

        return in_array($rawStatus, ['rejected', 'invalid', 'refused'], true)
            || str_starts_with($rawCode, 'BIZ_');
    }

    /**
     * Resolve a human-readable driver name from the gateway instance.
     */
    private function resolveDriverName(DsnGatewayInterface $gateway): string
    {
        return match (true) {
            $gateway instanceof NullDsnGateway => 'null',
            $gateway instanceof \App\Core\Workforce\Dsn\Gateway\FileDsnGateway => 'file',
            $gateway instanceof \App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway => 'net-entreprises',
            default => get_class($gateway),
        };
    }

    /**
     * Store a receipt/report from gateway response to disk.
     *
     * @return string|null  Path to stored receipt, or null if nothing to store
     */
    private function storeReceipt(DsnDeclaration $declaration, array $rawResponse, string $type): ?string
    {
        $content = json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (empty($content)) {
            return null;
        }

        $path = "workforce/dsn/{$declaration->company_id}/receipts/{$type}-{$declaration->id}.json";
        \Illuminate\Support\Facades\Storage::disk(config('workforce.dsn.file_disk', 'local'))->put($path, $content);

        return $path;
    }

    /**
     * Apply a terminal poll result (accepted or rejected).
     */
    private function applyTerminalPollResult(
        DsnDeclaration $declaration,
        int $actorId,
        DsnPollingResult $pollResult,
        int $durationMs,
    ): DsnDeclaration {
        if ($pollResult->gatewayStatus === 'cco_accepted') {
            $declaration->business_report_path = $pollResult->businessReportPath;
            $declaration->gateway_metadata = $pollResult->metadata;
            $declaration->next_poll_at = null;

            $this->auditPoll($declaration, $actorId, $durationMs, 'accepted');

            return $this->markAccepted($declaration, $actorId);
        }

        // ban_rejected or poll_timeout
        $declaration->business_report_path = $pollResult->businessReportPath;
        $declaration->gateway_error_code = $pollResult->errorCode;
        $declaration->gateway_error_message = $pollResult->errorMessage;
        $declaration->next_poll_at = null;

        $this->auditPoll($declaration, $actorId, $durationMs, 'rejected', $pollResult->errorMessage);

        return $this->markRejected($declaration, $actorId, $pollResult->errorMessage);
    }

    /**
     * Log an audit entry for a submission attempt.
     */
    private function auditAttempt(
        DsnDeclaration $declaration,
        int $actorId,
        int $attemptNumber,
        int $durationMs,
        string $result,
        ?string $error = null,
        ?string $reference = null,
        ?DsnGatewayInterface $gateway = null,
        ?\App\Core\Workforce\Dsn\PreflightResult $preflight = null,
        bool $retryable = false,
    ): void {
        app(AuditLogger::class)->logCompany(
            companyId: $declaration->company_id,
            action: 'dsn_declaration.submit_attempt',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.dsn.submit',
                    'period_month' => $declaration->period_month,
                    'attempt_number' => $attemptNumber,
                    'duration_ms' => $durationMs,
                    'gateway' => $gateway ? $this->resolveDriverName($gateway) : null,
                    'result' => $result,
                    'retryable' => $retryable,
                    'error_type' => $error,
                    'submission_reference' => $reference,
                    'payload_hash' => $declaration->payload_hash,
                    'preflight_warnings' => $preflight?->warnings ?? [],
                ],
            ],
        );
    }

    /**
     * Log an audit entry for a polling attempt.
     */
    private function auditPoll(
        DsnDeclaration $declaration,
        int $actorId,
        int $durationMs,
        string $result,
        ?string $error = null,
    ): void {
        app(AuditLogger::class)->logCompany(
            companyId: $declaration->company_id,
            action: 'dsn_declaration.poll_attempt',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.dsn.submit',
                    'period_month' => $declaration->period_month,
                    'duration_ms' => $durationMs,
                    'gateway_status' => $declaration->gateway_status,
                    'result' => $result,
                    'error' => $error,
                    'submission_reference' => $declaration->submission_reference,
                ],
            ],
        );
    }
}
