<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\CompanyDocumentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-397: Company document automation settings (show + update).
 */
class DocumentSettingController
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $settings = CompanyDocumentSetting::forCompany($company->id);

        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'auto_renew_enabled' => ['sometimes', 'boolean'],
            'renew_days_before' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'auto_remind_enabled' => ['sometimes', 'boolean'],
            'remind_after_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            // ADR-413: AI feature settings
            'ai_features' => ['sometimes', 'array'],
            'ai_features.ai_analysis_enabled' => ['sometimes', 'boolean'],
            'ai_features.ocr_enabled' => ['sometimes', 'boolean'],
            'ai_features.auto_fill_expiry' => ['sometimes', 'boolean'],
            'ai_features.auto_reject_type_mismatch' => ['sometimes', 'boolean'],
            'ai_features.notify_expiry_detected' => ['sometimes', 'boolean'],
            'ai_features.notify_validation_errors' => ['sometimes', 'boolean'],
            'ai_features.min_confidence_threshold' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        $settings = CompanyDocumentSetting::forCompany($company->id);
        $settings->update($validated);

        return response()->json([
            'message' => 'Document settings updated.',
            'settings' => $settings->fresh(),
        ]);
    }
}
