<?php

namespace App\Core\Ai;

use App\Core\Documents\CompanyDocumentSetting;
use App\Platform\Models\PlatformSetting;

/**
 * ADR-413: Resolves AI policy for a given module + company.
 *
 * Cascade (settings-driven, NOT plan-driven):
 *   1. Platform gate: no active provider → disabled
 *   2. Module settings (CompanyDocumentSetting.ai_features for documents)
 *   3. Fallback to platform defaults (PlatformSetting.ai.document_defaults)
 *
 * Each module resolves its own settings. Core AI stays neutral.
 */
class AiPolicyResolver
{
    public static function forModule(int $companyId, string $moduleKey): AiPolicy
    {
        // Gate 1: No active AI provider on platform → disabled
        if (PlatformAiModule::active()->doesntExist()) {
            return AiPolicy::disabled();
        }

        // Gate 2: Module-specific resolution
        return match ($moduleKey) {
            'documents' => self::resolveDocuments($companyId),
            default => AiPolicy::disabled(),
        };
    }

    private static function resolveDocuments(int $companyId): AiPolicy
    {
        $settings = CompanyDocumentSetting::forCompany($companyId);
        $features = $settings->ai_features ?? self::platformDefaults();

        return new AiPolicy(
            analysisEnabled: (bool) ($features['ai_analysis_enabled'] ?? true),
            ocrEnabled: (bool) ($features['ocr_enabled'] ?? true),
            autoFillExpiry: (bool) ($features['auto_fill_expiry'] ?? true),
            autoRejectTypeMismatch: (bool) ($features['auto_reject_type_mismatch'] ?? false),
            notifyExpiryDetected: (bool) ($features['notify_expiry_detected'] ?? true),
            notifyValidationErrors: (bool) ($features['notify_validation_errors'] ?? true),
            minConfidenceThreshold: ((int) ($features['min_confidence_threshold'] ?? 60)) / 100,
        );
    }

    private static function platformDefaults(): array
    {
        return PlatformSetting::instance()->ai['document_defaults'] ?? [];
    }
}
