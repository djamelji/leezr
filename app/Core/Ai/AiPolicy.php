<?php

namespace App\Core\Ai;

/**
 * ADR-413: Value object representing resolved AI policy for a module/company.
 *
 * Resolved by AiPolicyResolver. Contains NO business logic —
 * just the resolved toggles that DecisionServices consume.
 */
final class AiPolicy
{
    public function __construct(
        public readonly bool $analysisEnabled,
        public readonly bool $ocrEnabled,
        public readonly bool $autoFillExpiry,
        public readonly bool $autoRejectTypeMismatch,
        public readonly bool $notifyExpiryDetected,
        public readonly bool $notifyValidationErrors,
        public readonly float $minConfidenceThreshold,
    ) {}

    /**
     * Factory for fully disabled policy (no AI at all).
     */
    public static function disabled(): self
    {
        return new self(
            analysisEnabled: false,
            ocrEnabled: false,
            autoFillExpiry: false,
            autoRejectTypeMismatch: false,
            notifyExpiryDetected: false,
            notifyValidationErrors: false,
            minConfidenceThreshold: 1.0,
        );
    }

    public function toArray(): array
    {
        return [
            'analysis_enabled' => $this->analysisEnabled,
            'ocr_enabled' => $this->ocrEnabled,
            'auto_fill_expiry' => $this->autoFillExpiry,
            'auto_reject_type_mismatch' => $this->autoRejectTypeMismatch,
            'notify_expiry_detected' => $this->notifyExpiryDetected,
            'notify_validation_errors' => $this->notifyValidationErrors,
            'min_confidence_threshold' => $this->minConfidenceThreshold,
        ];
    }
}
