<?php

namespace App\Modules\Core\Documents\Services;

use App\Core\Ai\AiPolicy;
use App\Core\Ai\DTOs\AiDecisionResult;
use App\Core\Ai\DTOs\AiInsight;
use App\Core\Documents\DocumentAnalysisResult;

/**
 * ADR-413: Document-specific AI decision service.
 *
 * Consumes AiPolicy + DocumentAnalysisResult, returns AiDecisionResult.
 * RULE: This service MUST NOT write to DB, dispatch notifications,
 * or perform any side effect. It returns INTENTIONS only.
 * The Job/UseCase orchestrates the actual mutations.
 */
class DocumentAiDecisionService
{
    public function evaluate(
        AiPolicy $policy,
        DocumentAnalysisResult $result,
        ?string $expectedTypeCode,
        bool $hasExpiryDate,
    ): AiDecisionResult {
        $insights = [];

        // Source-level insight
        if ($result->source !== 'none') {
            $insights[] = new AiInsight(
                type: 'analysis_complete',
                severity: 'info',
                messageKey: 'documents.aiInsight.analysisComplete',
                messageParams: ['source' => $result->source],
            );
        }

        // Gate: confidence below threshold → noAction
        if ($result->confidence < $policy->minConfidenceThreshold) {
            $insights[] = new AiInsight(
                type: 'low_confidence',
                severity: 'warning',
                messageKey: 'documents.aiInsight.lowConfidence',
                messageParams: ['confidence' => round($result->confidence * 100)],
            );

            return AiDecisionResult::noAction($insights);
        }

        // Intention 1: auto-fill expiry date
        $shouldAutoFill = false;
        $detectedExpiry = null;

        if ($policy->autoFillExpiry && $result->expiryDate && ! $hasExpiryDate) {
            $shouldAutoFill = true;
            $detectedExpiry = $result->expiryDate;
            $insights[] = new AiInsight(
                type: 'auto_filled',
                severity: 'success',
                messageKey: 'documents.aiInsight.autoFilled',
                messageParams: ['date' => $result->expiryDate],
            );
        } elseif ($result->expiryDate && ! $hasExpiryDate && ! $policy->autoFillExpiry) {
            // Expiry detected but auto-fill disabled
            $insights[] = new AiInsight(
                type: 'expiry_detected',
                severity: 'info',
                messageKey: 'documents.aiInsight.expiryDetected',
                messageParams: ['date' => $result->expiryDate],
            );
        }

        // Intention 2: auto-reject type mismatch
        $shouldAutoReject = false;
        $rejectReason = null;

        if ($expectedTypeCode && $result->detectedType && $result->detectedType !== $expectedTypeCode) {
            if ($policy->autoRejectTypeMismatch) {
                $shouldAutoReject = true;
                $rejectReason = "Type mismatch: detected '{$result->detectedType}', expected '{$expectedTypeCode}'";
                $insights[] = new AiInsight(
                    type: 'auto_rejected',
                    severity: 'error',
                    messageKey: 'documents.aiInsight.autoRejected',
                    messageParams: [
                        'detected' => $result->detectedType,
                        'expected' => $expectedTypeCode,
                    ],
                );
            } else {
                $insights[] = new AiInsight(
                    type: 'type_mismatch',
                    severity: 'warning',
                    messageKey: 'documents.aiInsight.typeMismatch',
                    messageParams: [
                        'detected' => $result->detectedType,
                        'expected' => $expectedTypeCode,
                    ],
                );
            }
        }

        // Intention 3: notify expiry
        $shouldNotifyExpiry = false;
        if ($policy->notifyExpiryDetected && $result->expiryDate) {
            $shouldNotifyExpiry = true;
        }

        // Intention 4: notify validation errors
        $shouldNotifyErrors = false;
        if ($policy->notifyValidationErrors && ! empty($result->validationErrors)) {
            $shouldNotifyErrors = true;
            $insights[] = new AiInsight(
                type: 'validation_errors',
                severity: 'warning',
                messageKey: 'documents.aiInsight.validationErrors',
                messageParams: ['count' => count($result->validationErrors)],
                metadata: ['errors' => $result->validationErrors],
            );
        }

        return new AiDecisionResult(
            shouldAutoFillExpiry: $shouldAutoFill,
            detectedExpiryDate: $detectedExpiry,
            shouldAutoReject: $shouldAutoReject,
            autoRejectReason: $rejectReason,
            shouldNotifyExpiry: $shouldNotifyExpiry,
            shouldNotifyErrors: $shouldNotifyErrors,
            insights: $insights,
        );
    }
}
