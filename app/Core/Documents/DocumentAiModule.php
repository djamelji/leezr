<?php

namespace App\Core\Documents;

use App\Core\Ai\AiPolicy;
use App\Core\Ai\Contracts\AiModuleContract;
use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-436: AI module contract implementation for the Documents module.
 *
 * Migrated from the hardcoded AiPolicyResolver::resolveDocuments().
 * Declares document-specific AI capabilities, policy fields, and dispatch logic.
 */
final class DocumentAiModule implements AiModuleContract
{
    public function moduleKey(): string
    {
        return 'documents';
    }

    public function policyFields(): array
    {
        return [
            'ai_analysis_enabled' => ['type' => 'boolean', 'default' => true],
            'ocr_enabled' => ['type' => 'boolean', 'default' => true],
            'auto_fill_expiry' => ['type' => 'boolean', 'default' => true],
            'auto_reject_type_mismatch' => ['type' => 'boolean', 'default' => true],
            'notify_expiry_detected' => ['type' => 'boolean', 'default' => true],
            'notify_validation_errors' => ['type' => 'boolean', 'default' => true],
            'min_confidence_threshold' => ['type' => 'integer', 'default' => 60],
        ];
    }

    public function resolvePolicy(int $companyId): AiPolicy
    {
        $settings = CompanyDocumentSetting::forCompany($companyId);
        $features = $settings->ai_features ?? $this->platformDefaults();

        return new AiPolicy(
            analysisEnabled: (bool) ($features['ai_analysis_enabled'] ?? true),
            ocrEnabled: (bool) ($features['ocr_enabled'] ?? true),
            autoFillExpiry: (bool) ($features['auto_fill_expiry'] ?? true),
            autoRejectTypeMismatch: (bool) ($features['auto_reject_type_mismatch'] ?? true),
            notifyExpiryDetected: (bool) ($features['notify_expiry_detected'] ?? true),
            notifyValidationErrors: (bool) ($features['notify_validation_errors'] ?? true),
            minConfidenceThreshold: ((int) ($features['min_confidence_threshold'] ?? 60)) / 100,
        );
    }

    public function dispatchAnalysis(Model $entity): void
    {
        \App\Jobs\Documents\ProcessDocumentAiJob::dispatch(
            $entity::class,
            $entity->id,
            $entity->document_type_id,
        );
    }

    private function platformDefaults(): array
    {
        return PlatformSetting::instance()->ai['document_defaults'] ?? [];
    }
}
