<?php

namespace App\Core\Ai\DTOs;

/**
 * ADR-413: Result of a DecisionService evaluation — intentions only, NEVER mutations.
 *
 * The Job/UseCase reads these intentions and executes:
 * - DB updates (expires_at, status transitions)
 * - Notifications
 * - Insight storage
 *
 * DecisionService MUST NOT write to DB or dispatch notifications.
 */
final class AiDecisionResult
{
    /**
     * @param  AiInsight[]  $insights
     */
    public function __construct(
        public readonly bool $shouldAutoFillExpiry,
        public readonly ?string $detectedExpiryDate,
        public readonly bool $shouldAutoReject,
        public readonly ?string $autoRejectReason,
        public readonly bool $shouldNotifyExpiry,
        public readonly bool $shouldNotifyErrors,
        public readonly array $insights,
    ) {}

    /**
     * No action needed — all flags false.
     *
     * @param  AiInsight[]  $insights
     */
    public static function noAction(array $insights = []): self
    {
        return new self(
            shouldAutoFillExpiry: false,
            detectedExpiryDate: null,
            shouldAutoReject: false,
            autoRejectReason: null,
            shouldNotifyExpiry: false,
            shouldNotifyErrors: false,
            insights: $insights,
        );
    }

    public function hasAnyAction(): bool
    {
        return $this->shouldAutoFillExpiry
            || $this->shouldAutoReject
            || $this->shouldNotifyExpiry
            || $this->shouldNotifyErrors;
    }

    public function toArray(): array
    {
        return [
            'should_auto_fill_expiry' => $this->shouldAutoFillExpiry,
            'detected_expiry_date' => $this->detectedExpiryDate,
            'should_auto_reject' => $this->shouldAutoReject,
            'auto_reject_reason' => $this->autoRejectReason,
            'should_notify_expiry' => $this->shouldNotifyExpiry,
            'should_notify_errors' => $this->shouldNotifyErrors,
            'insights' => array_map(fn (AiInsight $i) => $i->toArray(), $this->insights),
        ];
    }
}
