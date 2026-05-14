<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnSubmissionLockService;

/**
 * Polls a submitted DsnDeclaration for asynchronous status updates.
 *
 * Wraps SubmitDsnDeclarationUseCase::poll() with:
 *   - Lock anti-concurrence (reuses submission lock)
 *   - Backoff scheduling (15min → 30min → 1h)
 *   - 72h timeout → poll_timeout + rejected
 *   - Per-attempt audit logging
 *
 * Sprint 7.4 — ADR-532
 */
class PollDsnDeclarationUseCase
{
    public function __construct(
        private SubmitDsnDeclarationUseCase $submitUseCase,
        private ?DsnSubmissionLockService $lockService = null,
    ) {
        $this->lockService ??= new DsnSubmissionLockService();
    }

    /**
     * Poll a single submitted declaration.
     *
     * @return array{declaration: DsnDeclaration, result: string, gateway_status: ?string}
     */
    public function execute(DsnDeclaration $declaration, int $actorId): array
    {
        // --- Guard: must be submitted ---
        if ($declaration->status !== DsnDeclaration::STATUS_SUBMITTED) {
            return [
                'declaration' => $declaration,
                'result' => 'skipped',
                'gateway_status' => $declaration->gateway_status,
            ];
        }

        // --- Guard: timeout check (72h default) ---
        $maxHours = (int) config('workforce.dsn.poll_max_duration_hours', 72);

        if ($declaration->submitted_at && $declaration->submitted_at->diffInHours(now()) >= $maxHours) {
            return $this->applyTimeout($declaration, $actorId, $maxHours);
        }

        // --- Lock (anti-concurrent poll) ---
        if (! $this->lockService->acquire($declaration->id, 120)) { // 2 min TTL for poll
            return [
                'declaration' => $declaration,
                'result' => 'locked',
                'gateway_status' => $declaration->gateway_status,
            ];
        }

        try {
            $startTime = hrtime(true);

            $polled = $this->submitUseCase->poll($declaration, $actorId);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Determine result from status change
            $result = match ($polled->status) {
                DsnDeclaration::STATUS_ACCEPTED => 'accepted',
                DsnDeclaration::STATUS_REJECTED => 'rejected',
                default => 'pending',
            };

            // Apply backoff for pending results
            if ($result === 'pending') {
                $this->applyBackoff($polled);
            }

            return [
                'declaration' => $polled,
                'result' => $result,
                'gateway_status' => $polled->gateway_status,
            ];

        } catch (\RuntimeException $e) {
            // Retryable transport error — apply backoff, don't reject
            $this->applyBackoff($declaration);

            app(AuditLogger::class)->logCompany(
                companyId: $declaration->company_id,
                action: 'dsn_declaration.poll_error',
                targetType: 'dsn_declaration',
                targetId: (string) $declaration->id,
                options: [
                    'actorId' => $actorId,
                    'metadata' => [
                        'category' => 'workforce.dsn.poll',
                        'error' => $e->getMessage(),
                        'retryable' => true,
                    ],
                ],
            );

            return [
                'declaration' => $declaration,
                'result' => 'error_retryable',
                'gateway_status' => $declaration->gateway_status,
            ];

        } catch (\Throwable $e) {
            // Non-retryable error
            app(AuditLogger::class)->logCompany(
                companyId: $declaration->company_id,
                action: 'dsn_declaration.poll_error',
                targetType: 'dsn_declaration',
                targetId: (string) $declaration->id,
                options: [
                    'actorId' => $actorId,
                    'metadata' => [
                        'category' => 'workforce.dsn.poll',
                        'error' => $e->getMessage(),
                        'retryable' => false,
                    ],
                ],
            );

            return [
                'declaration' => $declaration,
                'result' => 'error',
                'gateway_status' => $declaration->gateway_status,
            ];

        } finally {
            $this->lockService->release($declaration->id);
        }
    }

    /**
     * Apply exponential backoff for next poll scheduling.
     *
     * Attempt 1: +15 minutes
     * Attempt 2: +30 minutes
     * Attempt 3+: +60 minutes
     */
    private function applyBackoff(DsnDeclaration $declaration): void
    {
        $attemptCount = $declaration->attempt_count ?? 1;

        $delayMinutes = match (true) {
            $attemptCount <= 1 => 15,
            $attemptCount === 2 => 30,
            default => 60,
        };

        $declaration->next_poll_at = now()->addMinutes($delayMinutes);
        $declaration->attempt_count = $attemptCount + 1;
        $declaration->save();
    }

    /**
     * Handle polling timeout — mark as poll_timeout + rejected.
     */
    private function applyTimeout(DsnDeclaration $declaration, int $actorId, int $maxHours): array
    {
        $declaration->gateway_status = 'poll_timeout';
        $declaration->gateway_error_code = 'polling_timeout';
        $declaration->gateway_error_message = "No conclusive response after {$maxHours} hours.";
        $declaration->next_poll_at = null;
        $declaration->last_polled_at = now();

        $this->submitUseCase->markRejected(
            $declaration, $actorId,
            "Polling timeout: no response after {$maxHours}h."
        );

        app(AuditLogger::class)->logCompany(
            companyId: $declaration->company_id,
            action: 'dsn_declaration.poll_timeout',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.dsn.poll',
                    'max_hours' => $maxHours,
                    'submitted_at' => $declaration->submitted_at?->toIso8601String(),
                ],
            ],
        );

        return [
            'declaration' => $declaration,
            'result' => 'timeout',
            'gateway_status' => 'poll_timeout',
        ];
    }
}
